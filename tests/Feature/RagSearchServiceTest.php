<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\EmbeddingService;
use App\Services\RagSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RagSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_text_search_finds_chunk_by_keyword(): void
    {
        $document = Document::factory()->create(['title' => 'K8s guide', 'status' => 'embedded']);

        $match = DocumentChunk::factory()->create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content'     => 'Kubernetes deployment rollback strategies for production clusters.',
        ]);

        DocumentChunk::factory()->create([
            'content' => 'How to cook the perfect pasta carbonara at home.',
        ]);

        $results = $this->service()->search('kubernetes');

        $this->assertCount(1, $results);
        $this->assertSame($match->id, $results[0]['chunk_id']);
        $this->assertSame($document->id, $results[0]['document_id']);
        $this->assertSame($document->title, $results[0]['title']);
        $this->assertSame($document->url, $results[0]['url']);
        $this->assertSame(0, $results[0]['chunk_index']);
        $this->assertStringContainsString('Kubernetes', $results[0]['snippet']);
        $this->assertGreaterThan(0, $results[0]['score']);
    }

    public function test_vector_search_orders_by_similarity(): void
    {
        // Contenuti senza overlap lessicale con la query: solo il ramo
        // vettoriale può classificarli.
        $closest = DocumentChunk::factory()->create(['content' => 'First text about apples.']);
        $middle  = DocumentChunk::factory()->create(['content' => 'Second text about oranges.']);
        $far     = DocumentChunk::factory()->create(['content' => 'Third text about pears.']);

        $this->setEmbedding($closest, $this->makeVector([1.0]));
        $this->setEmbedding($middle, $this->makeVector([0.6, 0.8]));
        $this->setEmbedding($far, $this->makeVector([0.0, 1.0]));

        $results = $this->service()->search('quantum entanglement');

        $this->assertSame(
            [$closest->id, $middle->id, $far->id],
            array_column($results, 'chunk_id'),
        );
    }

    public function test_fusion_dedups_chunks_present_in_both_rankings(): void
    {
        // $both matcha la keyword ED è il più simile alla query vettoriale:
        // deve comparire una volta sola e sommare i contributi RRF dei due ranking.
        $both = DocumentChunk::factory()->create([
            'content' => 'Kubernetes cluster upgrade guide with zero downtime.',
        ]);
        $ftsOnly = DocumentChunk::factory()->create([
            'content' => 'Running kubernetes on bare metal servers.',
        ]);
        $vectorOnly = DocumentChunk::factory()->create([
            'content' => 'A totally unrelated basket of fresh fruit.',
        ]);

        $this->setEmbedding($both, $this->makeVector([1.0]));
        $this->setEmbedding($vectorOnly, $this->makeVector([0.8, 0.6]));

        $results = $this->service()->search('kubernetes');

        $ids = array_column($results, 'chunk_id');

        $this->assertCount(3, $results);
        $this->assertSame($ids, array_unique($ids), 'A chunk in both rankings must appear only once.');
        $this->assertSame($both->id, $ids[0], 'The chunk in both rankings must outrank single-ranking chunks.');
        $this->assertEqualsCanonicalizing([$both->id, $ftsOnly->id, $vectorOnly->id], $ids);
    }

    public function test_doc_type_filter_restricts_results(): void
    {
        [$articleChunk, $noteChunk] = $this->createFilterFixtures();

        $results = $this->service()->search('docker', 10, docType: 'note');

        $this->assertSame([$noteChunk->id], array_column($results, 'chunk_id'));
        $this->assertSame('note', $results[0]['doc_type']);
    }

    public function test_source_filter_restricts_results(): void
    {
        [$articleChunk, $noteChunk] = $this->createFilterFixtures();

        $results = $this->service()->search('docker', 10, source: 'intake');

        $this->assertSame([$articleChunk->id], array_column($results, 'chunk_id'));
        $this->assertSame('intake', $results[0]['source']);
    }

    public function test_limit_caps_the_number_of_results(): void
    {
        DocumentChunk::factory()->count(3)->sequence(
            ['content' => 'Docker networking basics.'],
            ['content' => 'Docker volumes explained.'],
            ['content' => 'Docker compose in production.'],
        )->create();

        $results = $this->service()->search('docker', 2);

        $this->assertCount(2, $results);
    }

    // --- helpers ---

    /**
     * @return array{0: DocumentChunk, 1: DocumentChunk} [chunk article/intake, chunk note/manual]
     */
    private function createFilterFixtures(): array
    {
        $articleDoc = Document::factory()->create(['doc_type' => 'article', 'source' => 'intake']);
        $noteDoc    = Document::factory()->create(['doc_type' => 'note', 'source' => 'manual']);

        $articleChunk = DocumentChunk::factory()->create([
            'document_id' => $articleDoc->id,
            'content'     => 'Docker hardening checklist for exposed hosts.',
        ]);
        $noteChunk = DocumentChunk::factory()->create([
            'document_id' => $noteDoc->id,
            'content'     => 'Docker rootless setup notes and pitfalls.',
        ]);

        $this->setEmbedding($articleChunk, $this->makeVector([1.0]));
        $this->setEmbedding($noteChunk, $this->makeVector([0.9, 0.43589]));

        return [$articleChunk, $noteChunk];
    }

    private function service(?array $queryVector = null): RagSearchService
    {
        $embeddings = $this->createMock(EmbeddingService::class);
        $embeddings->method('embedText')->willReturn($queryVector ?? $this->makeVector([1.0]));

        return new RagSearchService($embeddings);
    }

    /**
     * Vettore 1536d con le prime componenti assegnate e il resto a zero.
     *
     * @param  list<float> $leading
     * @return float[]
     */
    private function makeVector(array $leading): array
    {
        $vector = array_fill(0, (int) config('pipeline.embedding.dimensions', 1536), 0.0);

        foreach ($leading as $index => $value) {
            $vector[$index] = $value;
        }

        return $vector;
    }

    private function setEmbedding(DocumentChunk $chunk, array $vector): void
    {
        DB::table('document_chunks')
            ->where('id', $chunk->id)
            ->update(['embedding' => '[' . implode(',', $vector) . ']']);
    }
}
