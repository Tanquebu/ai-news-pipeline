<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\Dossier;
use Illuminate\Support\Facades\DB;

/**
 * Assegna i document embedded ai dossier tematici persistenti.
 *
 * L'embedding di un document è la MEDIA degli embedding dei suoi chunk
 * (calcolata in SQL con l'aggregato avg() di pgvector): rappresenta il
 * tema complessivo meglio del solo primo chunk. La media non è
 * unit-norm, ma la distanza coseno è invariante di scala, quindi il
 * confronto resta corretto.
 *
 * Sotto soglia NON viene creato alcun dossier: i dossier nascono dal
 * seed (`dossiers:seed`) o manualmente; il consolidamento notturno
 * (`dossiers:consolidate`) riprova gli orfani.
 */
class DossierAssignmentService
{
    /**
     * Confronta l'embedding del document con i centroidi dei dossier e,
     * sopra soglia, lo assegna al dossier più vicino aggiornandone il
     * centroide (media incrementale). Ritorna il dossier assegnato,
     * null se nessun match sopra soglia o embedding mancante.
     */
    public function assign(Document $document): ?Dossier
    {
        $embedding = $this->documentEmbedding($document->id);

        if ($embedding === null) {
            return null;
        }

        $threshold = (float) config('pipeline.dossier.similarity_threshold', 0.45);

        $match = DB::selectOne(
            'SELECT id, 1 - (centroid <=> ?) AS similarity
             FROM dossiers
             WHERE centroid IS NOT NULL
             ORDER BY centroid <=> ? ASC
             LIMIT 1',
            [$embedding, $embedding],
        );

        if ($match === null || $match->similarity < $threshold) {
            return null;
        }

        $this->attach($document->id, (int) $match->id, (float) $match->similarity, $embedding);

        return Dossier::find((int) $match->id);
    }

    /**
     * Embedding del document come media dei chunk embeddati, in formato
     * testuale pgvector ('[0.1,0.2,...]'). Null se nessun chunk ha vettore.
     */
    public function documentEmbedding(int $documentId): ?string
    {
        $embedding = DB::scalar(
            'SELECT avg(embedding)::text
             FROM document_chunks
             WHERE document_id = ? AND embedding IS NOT NULL',
            [$documentId],
        );

        return $embedding === null ? null : (string) $embedding;
    }

    /**
     * Aggancia il document al dossier (idempotente: la UNIQUE sul pivot fa
     * da guardia) e aggiorna il centroide con media incrementale pesata su
     * document_count. Se il centroide era il bootstrap da descrizione
     * (document_count = 0) viene rimpiazzato dall'embedding del document.
     */
    private function attach(int $documentId, int $dossierId, float $similarity, string $documentEmbedding): void
    {
        DB::transaction(function () use ($documentId, $dossierId, $similarity, $documentEmbedding): void {
            $dossier = DB::selectOne(
                'SELECT centroid::text AS centroid, document_count FROM dossiers WHERE id = ? FOR UPDATE',
                [$dossierId],
            );

            $now = now();

            $inserted = DB::table('document_dossier')->insertOrIgnore([
                'document_id' => $documentId,
                'dossier_id'  => $dossierId,
                'similarity'  => $similarity,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            if ($inserted === 0) {
                // Già assegnato: nessun doppio conteggio, centroide invariato.
                return;
            }

            $centroid = $this->incrementalCentroid(
                $dossier->centroid,
                (int) $dossier->document_count,
                $documentEmbedding,
            );

            DB::update(
                'UPDATE dossiers
                 SET centroid = ?, document_count = document_count + 1, updated_at = ?
                 WHERE id = ?',
                [$centroid, $now, $dossierId],
            );
        });
    }

    /**
     * Media incrementale: (centroide * n + embedding) / (n + 1).
     * Con n = 0 (o centroide assente) il nuovo centroide è l'embedding
     * stesso: il bootstrap da descrizione viene sostituito dal primo
     * document reale.
     */
    private function incrementalCentroid(?string $currentCentroid, int $documentCount, string $documentEmbedding): string
    {
        if ($currentCentroid === null || $documentCount === 0) {
            return $documentEmbedding;
        }

        $centroid  = $this->vectorToArray($currentCentroid);
        $embedding = $this->vectorToArray($documentEmbedding);

        $updated = [];

        foreach ($centroid as $i => $value) {
            $updated[$i] = ($value * $documentCount + $embedding[$i]) / ($documentCount + 1);
        }

        return $this->arrayToVector($updated);
    }

    /** @return float[] */
    private function vectorToArray(string $vector): array
    {
        return array_map('floatval', explode(',', trim($vector, '[]')));
    }

    /** @param float[] $values */
    private function arrayToVector(array $values): string
    {
        return '[' . implode(',', $values) . ']';
    }
}
