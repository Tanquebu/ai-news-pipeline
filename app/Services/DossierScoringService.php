<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dossier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scoring SPIEGABILE dei dossier per la candidatura ai brief settimanali.
 *
 * Quattro componenti, ognuna normalizzata in [0, 1] e combinata con pesi
 * configurabili (config/pipeline.php → dossier.scoring):
 *
 * - volume:    document ingestati nella finestra, con saturazione (oltre la
 *              soglia la componente resta 1.0). La saturazione rende lo score
 *              robusto ai dossier sbilanciati/catch-all: 123 document non
 *              valgono 12 volte più di 10.
 * - diversity: fonti distinte (documents.source) nella finestra, saturata.
 * - recency:   decadimento esponenziale sull'età dell'ultimo document del
 *              dossier (half-life configurabile).
 * - cohesion:  similarità media document↔centroide registrata sul pivot al
 *              momento dell'assegnazione (già in [0, 1]).
 *
 * Il breakdown per componente (raw → normalized → weight → weighted_value)
 * più l'esito dei criteri di candidatura viene persistito in
 * dossiers.score_breakdown: la motivazione è sempre ricostruibile, lo score
 * non è mai un numero opaco. explain() la rende in forma testuale.
 *
 * Candidatura a brief (criteri minimi, valutati sulla finestra di attività):
 * almeno `candidate_min_documents` document E almeno `candidate_min_sources`
 * fonti distinte. La finestra stessa codifica il requisito di recency.
 */
class DossierScoringService
{
    /**
     * Calcola score, breakdown e candidatura del dossier SENZA persistere.
     *
     * @return array<string, mixed> breakdown completo (score incluso)
     */
    public function evaluate(Dossier $dossier): array
    {
        $config = (array) config('pipeline.dossier.scoring', []);

        $windowDays          = (int) ($config['window_days'] ?? 30);
        $volumeSaturation    = max(1, (int) ($config['volume_saturation'] ?? 10));
        $diversitySaturation = max(1, (int) ($config['diversity_saturation'] ?? 4));
        $halfLifeDays        = max(0.1, (float) ($config['recency_half_life_days'] ?? 7));

        $weights = [
            'volume'    => (float) ($config['weight_volume'] ?? 0.35),
            'diversity' => (float) ($config['weight_diversity'] ?? 0.25),
            'recency'   => (float) ($config['weight_recency'] ?? 0.25),
            'cohesion'  => (float) ($config['weight_cohesion'] ?? 0.15),
        ];

        $minDocuments = (int) ($config['candidate_min_documents'] ?? 3);
        $minSources   = (int) ($config['candidate_min_sources'] ?? 2);

        $windowStart = now()->subDays($windowDays);

        // Volume e diversità fonti: solo i document ingestati nella finestra.
        $windowStats = DB::table('document_dossier')
            ->join('documents', 'documents.id', '=', 'document_dossier.document_id')
            ->where('document_dossier.dossier_id', $dossier->id)
            ->where('documents.created_at', '>=', $windowStart)
            ->selectRaw('COUNT(*) AS documents_in_window, COUNT(DISTINCT documents.source) AS sources_in_window')
            ->first();

        $documentsInWindow = (int) ($windowStats->documents_in_window ?? 0);
        $sourcesInWindow   = (int) ($windowStats->sources_in_window ?? 0);

        // Recency e coesione: su tutti i membri del dossier.
        $memberStats = DB::table('document_dossier')
            ->join('documents', 'documents.id', '=', 'document_dossier.document_id')
            ->where('document_dossier.dossier_id', $dossier->id)
            ->selectRaw('MAX(documents.created_at) AS last_document_at, AVG(document_dossier.similarity) AS avg_similarity')
            ->first();

        $lastDocumentAt = $memberStats->last_document_at ?? null;
        $avgSimilarity  = $memberStats->avg_similarity === null ? null : (float) $memberStats->avg_similarity;

        // --- Componenti normalizzate ---

        $volumeNorm = min($documentsInWindow / $volumeSaturation, 1.0);

        $diversityNorm = min($sourcesInWindow / $diversitySaturation, 1.0);

        $daysSinceLast = null;
        $recencyNorm   = 0.0;

        if ($lastDocumentAt !== null) {
            $daysSinceLast = round(
                max(0.0, Carbon::parse($lastDocumentAt)->diffInSeconds(now(), true)) / 86400,
                2,
            );
            $recencyNorm = 2 ** (-$daysSinceLast / $halfLifeDays);
        }

        // La similarità coseno sopra soglia è già in [0, 1]: clamp difensivo.
        $cohesionNorm = $avgSimilarity === null ? 0.0 : max(0.0, min($avgSimilarity, 1.0));

        $components = [
            'volume' => [
                'raw'        => $documentsInWindow,
                'saturation' => $volumeSaturation,
                'normalized' => round($volumeNorm, 4),
            ],
            'diversity' => [
                'raw'        => $sourcesInWindow,
                'saturation' => $diversitySaturation,
                'normalized' => round($diversityNorm, 4),
            ],
            'recency' => [
                'days_since_last_document' => $daysSinceLast,
                'half_life_days'           => $halfLifeDays,
                'normalized'               => round($recencyNorm, 4),
            ],
            'cohesion' => [
                'avg_similarity' => $avgSimilarity === null ? null : round($avgSimilarity, 4),
                'normalized'     => round($cohesionNorm, 4),
            ],
        ];

        $score = 0.0;

        foreach ($components as $name => $component) {
            $weighted = $component['normalized'] * $weights[$name];
            $score += $weighted;

            $components[$name]['weight']         = $weights[$name];
            $components[$name]['weighted_value'] = round($weighted, 4);
        }

        $checks = [
            'min_documents_in_window' => [
                'required' => $minDocuments,
                'actual'   => $documentsInWindow,
                'passed'   => $documentsInWindow >= $minDocuments,
            ],
            'min_distinct_sources_in_window' => [
                'required' => $minSources,
                'actual'   => $sourcesInWindow,
                'passed'   => $sourcesInWindow >= $minSources,
            ],
        ];

        $isCandidate = collect($checks)->every(fn (array $check) => $check['passed']);

        return [
            'window_days' => $windowDays,
            'components'  => $components,
            'score'       => round($score, 4),
            'candidacy'   => [
                'is_candidate' => $isCandidate,
                'checks'       => $checks,
            ],
        ];
    }

    /**
     * Persiste score, breakdown, flag di candidatura e timestamp sul dossier.
     *
     * @param array<string, mixed> $breakdown output di evaluate()
     */
    public function persist(Dossier $dossier, array $breakdown): void
    {
        $dossier->update([
            'brief_score'        => $breakdown['score'],
            'score_breakdown'    => $breakdown,
            'is_brief_candidate' => $breakdown['candidacy']['is_candidate'],
            'scored_at'          => now(),
        ]);
    }

    /**
     * Motivazione leggibile ricostruita dal breakdown (una riga per
     * componente più l'esito dei criteri di candidatura).
     *
     * @param array<string, mixed> $breakdown output di evaluate() o contenuto
     *                                        di dossiers.score_breakdown
     */
    public function explain(array $breakdown): string
    {
        $components = $breakdown['components'];
        $candidacy  = $breakdown['candidacy'];
        $window     = $breakdown['window_days'];

        $lines = [];

        $lines[] = sprintf(
            'score %.4f — %s',
            $breakdown['score'],
            $candidacy['is_candidate'] ? 'CANDIDATO a brief' : 'non candidato',
        );

        $lines[] = sprintf(
            '  volume:    %d document nella finestra di %dgg (saturazione %d) -> %.2f x peso %.2f = %.4f',
            $components['volume']['raw'],
            $window,
            $components['volume']['saturation'],
            $components['volume']['normalized'],
            $components['volume']['weight'],
            $components['volume']['weighted_value'],
        );

        $lines[] = sprintf(
            '  diversity: %d fonti distinte nella finestra (saturazione %d) -> %.2f x peso %.2f = %.4f',
            $components['diversity']['raw'],
            $components['diversity']['saturation'],
            $components['diversity']['normalized'],
            $components['diversity']['weight'],
            $components['diversity']['weighted_value'],
        );

        $lines[] = $components['recency']['days_since_last_document'] === null
            ? sprintf(
                '  recency:   nessun document assegnato -> %.2f x peso %.2f = %.4f',
                $components['recency']['normalized'],
                $components['recency']['weight'],
                $components['recency']['weighted_value'],
            )
            : sprintf(
                '  recency:   ultimo document %.1fgg fa (half-life %.1fgg) -> %.2f x peso %.2f = %.4f',
                $components['recency']['days_since_last_document'],
                $components['recency']['half_life_days'],
                $components['recency']['normalized'],
                $components['recency']['weight'],
                $components['recency']['weighted_value'],
            );

        $lines[] = $components['cohesion']['avg_similarity'] === null
            ? sprintf(
                '  cohesion:  nessuna similarità registrata -> %.2f x peso %.2f = %.4f',
                $components['cohesion']['normalized'],
                $components['cohesion']['weight'],
                $components['cohesion']['weighted_value'],
            )
            : sprintf(
                '  cohesion:  similarità media col centroide %.2f -> %.2f x peso %.2f = %.4f',
                $components['cohesion']['avg_similarity'],
                $components['cohesion']['normalized'],
                $components['cohesion']['weight'],
                $components['cohesion']['weighted_value'],
            );

        $checks = $candidacy['checks'];

        $lines[] = sprintf(
            '  criteri:   >=%d document in finestra (%d %s), >=%d fonti distinte (%d %s)',
            $checks['min_documents_in_window']['required'],
            $checks['min_documents_in_window']['actual'],
            $checks['min_documents_in_window']['passed'] ? 'OK' : 'KO',
            $checks['min_distinct_sources_in_window']['required'],
            $checks['min_distinct_sources_in_window']['actual'],
            $checks['min_distinct_sources_in_window']['passed'] ? 'OK' : 'KO',
        );

        return implode("\n", $lines);
    }
}
