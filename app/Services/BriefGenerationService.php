<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LLMClient;
use App\Models\Brief;
use App\Models\Document;
use App\Models\Dossier;
use App\Support\LlmJson;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Generazione dei brief editoriali settimanali dai dossier candidati.
 *
 * Un brief NON è una bozza di contenuto: è un dossier informativo (formato
 * roadmap v2 §3-M3) per decidere se e come produrre un contenuto — tesi,
 * claim chiave con evidenze, controargomenti, claim rischiosi, formato
 * suggerito, angoli editoriali, fonti citabili con URL e motivazione della
 * selezione (spiegazione dello score, mai un numero opaco).
 *
 * Idempotenza: al massimo un brief per dossier per settimana (period_start =
 * lunedì della settimana corrente), garantita sia dalla selezione dei
 * candidati sia dal vincolo unique (dossier_id, period_start) sul DB.
 */
class BriefGenerationService
{
    public function __construct(
        private readonly LLMClient $llm,
        private readonly DossierScoringService $scoring,
    ) {}

    /**
     * Inizio (lunedì) della settimana corrente: chiave di idempotenza dei
     * brief. Lo schedule gira la domenica, quindi il brief copre la
     * settimana che si sta chiudendo.
     */
    public function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfWeek();
    }

    /**
     * Dossier candidati a brief per il periodo, ordinati per score, esclusi
     * quelli che hanno già un brief per la settimana corrente.
     *
     * @return Collection<int, Dossier>
     */
    public function candidates(CarbonInterface $periodStart, int $limit): Collection
    {
        return Dossier::query()
            ->where('is_brief_candidate', true)
            ->whereDoesntHave(
                'briefs',
                fn ($query) => $query->where('period_start', $periodStart->toDateString()),
            )
            ->orderByRaw('brief_score DESC NULLS LAST')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * I document più rilevanti del dossier nella finestra di attività dello
     * scoring: prima i più affini al centroide (similarity sul pivot), a
     * parità i più recenti. Sono l'input della sintesi e le fonti citabili.
     *
     * @return Collection<int, Document>
     */
    public function topDocuments(Dossier $dossier): Collection
    {
        $windowDays = (int) config('pipeline.dossier.scoring.window_days', 30);
        $limit      = max(1, (int) config('pipeline.briefs.top_documents', 8));

        return $dossier->documents()
            ->where('documents.created_at', '>=', now()->subDays($windowDays))
            ->orderByDesc('document_dossier.similarity')
            ->orderByDesc('documents.created_at')
            ->limit($limit)
            ->get([
                'documents.id',
                'documents.title',
                'documents.url',
                'documents.source',
                'documents.summary',
                'documents.created_at',
            ]);
    }

    /**
     * Genera e persiste il brief per un dossier: sintesi via LLM sui top
     * document, fonti citabili e motivazione (score spiegabile) dal dossier.
     *
     * @param Collection<int, Document> $documents output di topDocuments()
     *
     * @throws \JsonException se la risposta del modello non è JSON valido
     */
    public function generate(Dossier $dossier, CarbonInterface $periodStart, Collection $documents): Brief
    {
        $prompt = $this->buildPrompt($dossier, $documents);

        $maxTokens = max(1024, (int) config('pipeline.briefs.max_tokens', 4096));

        $raw  = $this->llm->complete($prompt, maxTokens: $maxTokens);
        $data = LlmJson::decode($raw);

        $breakdown = $dossier->score_breakdown;

        // Motivazione leggibile della selezione, ricostruita dal breakdown
        // persistito da dossiers:score (se presente e completo).
        $whyNow = null;

        if (is_array($breakdown) && isset($breakdown['score'], $breakdown['window_days'], $breakdown['components'], $breakdown['candidacy'])) {
            $whyNow = $this->scoring->explain($breakdown);
        }

        $sources = $documents->map(fn (Document $document) => [
            'title'       => $document->title,
            'url'         => $document->url,
            'source'      => $document->source,
            'ingested_at' => $document->created_at?->toDateString(),
        ])->values()->all();

        return Brief::create([
            'dossier_id'   => $dossier->id,
            'period_start' => $periodStart->toDateString(),
            'title'        => (string) ($data['title'] ?? $dossier->name),
            'score'        => $dossier->brief_score,
            'status'       => Brief::STATUS_DRAFT,
            'payload'      => [
                'theme'            => $dossier->name,
                'thesis'           => $data['thesis'] ?? null,
                'key_claims'       => $data['key_claims'] ?? [],
                'counterarguments' => $data['counterarguments'] ?? [],
                'risky_claims'     => $data['risky_claims'] ?? [],
                'suggested_format' => $data['suggested_format'] ?? null,
                'editorial_angles' => $data['editorial_angles'] ?? [],
                'why_now'          => $whyNow,
                'sources'          => $sources,
                'score_breakdown'  => $breakdown,
            ],
        ]);
    }

    /**
     * Prompt di sintesi del brief: stesso pattern di SynthesizeClusterJob
     * (istruzioni in italiano, risposta SOLO JSON strutturato).
     *
     * @param Collection<int, Document> $documents
     */
    public function buildPrompt(Dossier $dossier, Collection $documents): string
    {
        $docList = $documents->values()->map(function (Document $document, int $index) {
            $number = $index + 1;

            $lines = [
                "[{$number}] {$document->title} — {$document->source} — {$document->url}",
            ];

            if (! empty($document->summary)) {
                $lines[] = '    ' . str_replace("\n", ' ', $document->summary);
            }

            return implode("\n", $lines);
        })->join("\n");

        $description = $dossier->description ?? '(nessuna descrizione)';
        $count       = $documents->count();

        return <<<PROMPT
        Sei un analista editoriale. Prepara un BRIEF INFORMATIVO settimanale su un
        tema (dossier) a partire dai documenti raccolti. Il brief serve a decidere
        se e come produrre un contenuto (post LinkedIn o articolo per il sito):
        NON è una bozza di contenuto.

        TEMA (dossier): {$dossier->name}
        DESCRIZIONE: {$description}

        DOCUMENTI ({$count}, ordinati per rilevanza):
        {$docList}

        Restituisci ESCLUSIVAMENTE un oggetto JSON valido con questi campi:
        {
          "title": "titolo sintetico del brief (max 100 caratteri)",
          "thesis": "tesi centrale del tema in 2-4 frasi: cosa sta succedendo e qual è la lettura d'insieme",
          "key_claims": [{"claim": "affermazione chiave supportata dai documenti", "source_urls": ["url dei documenti che la supportano"]}],
          "counterarguments": ["controargomento o lettura alternativa credibile"],
          "risky_claims": ["affermazione presente nelle fonti ma debole o non verificata, da NON usare senza verifica"],
          "suggested_format": "linkedin-post | site-article | linkografia | skip",
          "editorial_angles": ["possibile angolo editoriale concreto"]
        }

        REGOLE:
        - 3-6 key_claims, ognuno con almeno un url preso ESCLUSIVAMENTE dai DOCUMENTI elencati
        - Non inventare fatti, numeri o url: usa solo ciò che è nei documenti
        - 1-3 counterarguments; "risky_claims" può essere una lista vuota
        - "suggested_format" vale "skip" se il materiale non giustifica un contenuto
        - Rispondi con SOLO JSON valido, nessun markdown, nessun testo extra
        PROMPT;
    }
}
