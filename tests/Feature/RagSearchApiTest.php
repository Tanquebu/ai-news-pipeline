<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RagSearchApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['pipeline.api_token' => $this->token]);

        $embeddings = $this->createMock(EmbeddingService::class);
        $embeddings->method('embedText')->willReturn($this->makeVector());
        $this->app->instance(EmbeddingService::class, $embeddings);
    }

    // --- GET /api/rag/search ---

    public function test_search_returns_200_with_expected_structure(): void
    {
        $document = Document::factory()->create(['title' => 'Docker guide']);

        DocumentChunk::factory()->create([
            'document_id' => $document->id,
            'content'     => 'Docker compose networking between containers.',
        ]);

        $response = $this->authed()->getJson('/api/rag/search?q=docker');

        $response->assertOk()
            ->assertJsonPath('query', 'docker')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.document_id', $document->id)
            ->assertJsonPath('results.0.title', 'Docker guide')
            ->assertJsonStructure([
                'query',
                'count',
                'results' => [
                    ['chunk_id', 'document_id', 'title', 'url', 'doc_type', 'source', 'chunk_index', 'snippet', 'score'],
                ],
            ]);

        $this->assertArrayNotHasKey('embedding', $response->json('results.0'));
    }

    public function test_search_applies_limit_and_filters_from_query_string(): void
    {
        $articleDoc = Document::factory()->create(['doc_type' => 'article', 'source' => 'intake']);
        $noteDoc    = Document::factory()->create(['doc_type' => 'note', 'source' => 'manual']);

        DocumentChunk::factory()->create([
            'document_id' => $articleDoc->id,
            'content'     => 'Docker hardening for public hosts.',
        ]);
        $noteChunk = DocumentChunk::factory()->create([
            'document_id' => $noteDoc->id,
            'content'     => 'Docker rootless notes.',
        ]);

        $response = $this->authed()->getJson('/api/rag/search?q=docker&limit=1&doc_type=note&source=manual');

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.chunk_id', $noteChunk->id);
    }

    public function test_search_requires_q_parameter(): void
    {
        $this->authed()->getJson('/api/rag/search')->assertStatus(422);
    }

    public function test_search_rejects_invalid_doc_type(): void
    {
        $this->authed()->getJson('/api/rag/search?q=docker&doc_type=video')->assertStatus(422);
    }

    public function test_search_requires_api_token(): void
    {
        $this->getJson('/api/rag/search?q=docker')->assertStatus(401);
    }

    // --- GET /api/documents/{id} ---

    public function test_show_returns_document_with_chunks_without_embeddings(): void
    {
        $document = Document::factory()->create(['status' => 'embedded']);

        $chunks = collect([0, 1])->map(
            fn (int $index) => DocumentChunk::factory()->create([
                'document_id' => $document->id,
                'chunk_index' => $index,
            ])
        );

        DB::table('document_chunks')
            ->where('id', $chunks->first()->id)
            ->update(['embedding' => '[' . implode(',', $this->makeVector()) . ']']);

        $response = $this->authed()->getJson("/api/documents/{$document->id}");

        $response->assertOk()
            ->assertJsonPath('document.id', $document->id)
            ->assertJsonPath('document.title', $document->title)
            ->assertJsonPath('document.status', 'embedded')
            ->assertJsonCount(2, 'document.chunks')
            ->assertJsonPath('document.chunks.0.chunk_index', 0)
            ->assertJsonPath('document.chunks.1.chunk_index', 1);

        foreach ($response->json('document.chunks') as $chunk) {
            $this->assertArrayNotHasKey('embedding', $chunk);
        }
    }

    public function test_show_returns_404_for_missing_document(): void
    {
        $this->authed()->getJson('/api/documents/999999')->assertStatus(404);
    }

    public function test_show_requires_api_token(): void
    {
        $document = Document::factory()->create();

        $this->getJson("/api/documents/{$document->id}")->assertStatus(401);
    }

    // --- helpers ---

    private function authed(): static
    {
        return $this->withHeaders(['X-API-Token' => $this->token]);
    }

    /** @return float[] */
    private function makeVector(): array
    {
        $vector    = array_fill(0, (int) config('pipeline.embedding.dimensions', 1536), 0.0);
        $vector[0] = 1.0;

        return $vector;
    }
}
