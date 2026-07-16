<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ricerca ibrida sul corpus documentale (documents/document_chunks):
 * full-text search PostgreSQL + similarità vettoriale pgvector, con
 * fusione dei due ranking via Reciprocal Rank Fusion (RRF).
 *
 * Ogni risultato porta con sé la fonte citabile: document_id, title,
 * url, chunk_index, snippet del contenuto e score di fusione.
 *
 * Config FTS 'simple': vedi la migrazione add_fts_index_to_document_chunks —
 * l'espressione to_tsvector('simple', content) deve combaciare con l'indice GIN.
 */
class RagSearchService
{
    /**
     * Costante k della RRF (score = Σ 1/(k + rank)): 60 è il valore canonico
     * del paper originale, smorza il dominio delle prime posizioni di un
     * singolo ranking a favore dei risultati presenti in entrambi.
     */
    private const RRF_K = 60;

    /**
     * Pool minimo di candidati estratti da ciascun ranking prima della
     * fusione: più ampio del limit richiesto, così la RRF può far emergere
     * chunk presenti in entrambe le liste anche se non ai vertici di una.
     */
    private const MIN_CANDIDATE_POOL = 50;

    private const SNIPPET_LENGTH = 300;

    public function __construct(private readonly EmbeddingService $embeddings) {}

    /**
     * @return list<array{chunk_id: int, document_id: int, title: string, url: string|null, doc_type: string, source: string, chunk_index: int, snippet: string, score: float}>
     */
    public function search(string $query, int $limit = 10, ?string $docType = null, ?string $source = null): array
    {
        $pool = max($limit, self::MIN_CANDIDATE_POOL);

        $rankings = [
            $this->fullTextRanking($query, $pool, $docType, $source),
            $this->vectorRanking($query, $pool, $docType, $source),
        ];

        $scores = $this->fuse($rankings);

        return $this->hydrate(array_slice(array_keys($scores), 0, $limit), $scores);
    }

    /**
     * Ranking full-text: match booleano con plainto_tsquery (AND fra i
     * termini), ordinato per ts_rank. Stessa espressione dell'indice GIN.
     *
     * @return list<int> chunk id in ordine di rilevanza
     */
    private function fullTextRanking(string $query, int $pool, ?string $docType, ?string $source): array
    {
        [$filterSql, $filterBindings] = $this->documentFilters($docType, $source);

        $rows = DB::select(
            "SELECT dc.id
             FROM document_chunks dc
             JOIN documents d ON d.id = dc.document_id
             WHERE to_tsvector('simple', dc.content) @@ plainto_tsquery('simple', ?)
             {$filterSql}
             ORDER BY ts_rank(to_tsvector('simple', dc.content), plainto_tsquery('simple', ?)) DESC, dc.id ASC
             LIMIT ?",
            [$query, ...$filterBindings, $query, $pool],
        );

        return array_map(static fn (object $row): int => (int) $row->id, $rows);
    }

    /**
     * Ranking vettoriale: embedding della query e ordinamento per distanza
     * coseno (operatore <=>, stesso idioma pgvector del clustering).
     *
     * @return list<int> chunk id in ordine di similarità
     */
    private function vectorRanking(string $query, int $pool, ?string $docType, ?string $source): array
    {
        $queryVector = '[' . implode(',', $this->embeddings->embedText($query)) . ']';

        [$filterSql, $filterBindings] = $this->documentFilters($docType, $source);

        $rows = DB::select(
            "SELECT dc.id
             FROM document_chunks dc
             JOIN documents d ON d.id = dc.document_id
             WHERE dc.embedding IS NOT NULL
             {$filterSql}
             ORDER BY dc.embedding <=> ? ASC, dc.id ASC
             LIMIT ?",
            [...$filterBindings, $queryVector, $pool],
        );

        return array_map(static fn (object $row): int => (int) $row->id, $rows);
    }

    /**
     * Reciprocal Rank Fusion: score(chunk) = Σ_ranking 1/(k + posizione).
     * La somma per chunk id deduplica i chunk presenti in entrambi i ranking.
     *
     * @param  list<list<int>> $rankings
     * @return array<int, float> chunk_id => score, ordinato per score decrescente
     */
    private function fuse(array $rankings): array
    {
        $scores = [];

        foreach ($rankings as $ranking) {
            foreach ($ranking as $position => $chunkId) {
                $scores[$chunkId] = ($scores[$chunkId] ?? 0.0) + 1.0 / (self::RRF_K + $position + 1);
            }
        }

        $ids = array_keys($scores);
        usort($ids, fn (int $a, int $b): int => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));

        $sorted = [];
        foreach ($ids as $id) {
            $sorted[$id] = $scores[$id];
        }

        return $sorted;
    }

    /**
     * Carica i metadati citabili dei chunk selezionati, preservando
     * l'ordine di fusione. Nessun embedding nel payload.
     *
     * @param  list<int>         $orderedIds
     * @param  array<int, float> $scores
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $orderedIds, array $scores): array
    {
        if ($orderedIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));

        $rows = DB::select(
            "SELECT dc.id, dc.document_id, dc.chunk_index, dc.content,
                    d.title, d.url, d.doc_type, d.source
             FROM document_chunks dc
             JOIN documents d ON d.id = dc.document_id
             WHERE dc.id IN ({$placeholders})",
            $orderedIds,
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }

        $results = [];

        foreach ($orderedIds as $chunkId) {
            $row = $byId[$chunkId] ?? null;

            if ($row === null) {
                continue;
            }

            $results[] = [
                'chunk_id'    => (int) $row->id,
                'document_id' => (int) $row->document_id,
                'title'       => $row->title,
                'url'         => $row->url,
                'doc_type'    => $row->doc_type,
                'source'      => $row->source,
                'chunk_index' => (int) $row->chunk_index,
                'snippet'     => Str::limit($row->content, self::SNIPPET_LENGTH),
                'score'       => round($scores[$chunkId], 6),
            ];
        }

        return $results;
    }

    /**
     * Frammento SQL (e binding) dei filtri opzionali sul document padre.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function documentFilters(?string $docType, ?string $source): array
    {
        $sql      = '';
        $bindings = [];

        if ($docType !== null) {
            $sql .= ' AND d.doc_type = ?';
            $bindings[] = $docType;
        }

        if ($source !== null) {
            $sql .= ' AND d.source = ?';
            $bindings[] = $source;
        }

        return [$sql, $bindings];
    }
}
