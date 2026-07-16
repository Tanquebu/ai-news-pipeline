<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AssignDocumentToDossierJob;
use App\Jobs\EmbedDocumentChunksJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class EmbedDocumentChunksJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([AssignDocumentToDossierJob::class]);
    }

    public function test_job_embeds_all_chunks_and_marks_document_embedded(): void
    {
        $document = Document::factory()->create(['status' => 'chunked']);

        $chunks = collect([0, 1])->map(
            fn (int $index) => DocumentChunk::factory()->create([
                'document_id' => $document->id,
                'chunk_index' => $index,
            ])
        );

        $service = $this->createMock(EmbeddingService::class);
        $service->expects($this->exactly(2))
            ->method('embedText')
            ->willReturn($this->makeEmbedding());

        (new EmbedDocumentChunksJob($document->id))->handle($service);

        foreach ($chunks as $chunk) {
            $stored = DB::scalar('SELECT embedding::text FROM document_chunks WHERE id = ?', [$chunk->id]);
            $this->assertNotNull($stored);
        }

        $this->assertSame('embedded', $document->fresh()->status);
    }

    public function test_job_skips_chunks_that_already_have_an_embedding(): void
    {
        $document = Document::factory()->create(['status' => 'chunked']);

        $embedded = DocumentChunk::factory()->create([
            'document_id' => $document->id,
            'chunk_index' => 0,
        ]);

        DB::table('document_chunks')
            ->where('id', $embedded->id)
            ->update(['embedding' => '[' . implode(',', $this->makeEmbedding()) . ']']);

        DocumentChunk::factory()->create([
            'document_id' => $document->id,
            'chunk_index' => 1,
        ]);

        $service = $this->createMock(EmbeddingService::class);
        $service->expects($this->once())
            ->method('embedText')
            ->willReturn($this->makeEmbedding());

        (new EmbedDocumentChunksJob($document->id))->handle($service);

        $this->assertSame('embedded', $document->fresh()->status);
        $this->assertSame(
            0,
            $document->chunks()->whereNull('embedding')->count()
        );
    }

    public function test_job_dispatches_dossier_assignment_after_embedding(): void
    {
        $document = Document::factory()->create(['status' => 'chunked']);

        DocumentChunk::factory()->create([
            'document_id' => $document->id,
            'chunk_index' => 0,
        ]);

        $service = $this->createMock(EmbeddingService::class);
        $service->method('embedText')->willReturn($this->makeEmbedding());

        (new EmbedDocumentChunksJob($document->id))->handle($service);

        Bus::assertDispatched(
            AssignDocumentToDossierJob::class,
            fn (AssignDocumentToDossierJob $job) => $job->documentId === $document->id
        );
    }

    public function test_embedding_failure_marks_document_as_failed(): void
    {
        $document = Document::factory()->create(['status' => 'chunked']);

        DocumentChunk::factory()->create([
            'document_id' => $document->id,
            'chunk_index' => 0,
        ]);

        $service = $this->createMock(EmbeddingService::class);
        $service->method('embedText')
            ->willThrowException(new RuntimeException('embedding API down'));

        $job = new EmbedDocumentChunksJob($document->id);

        try {
            $job->handle($service);
            $this->fail('Expected a RuntimeException from the embedding service.');
        } catch (RuntimeException) {
            // La coda esaurisce i tentativi e invoca failed().
            $job->failed(new RuntimeException('embedding API down'));
        }

        $this->assertSame('failed', $document->fresh()->status);
    }

    // --- helpers ---

    /** @return float[] */
    private function makeEmbedding(): array
    {
        $vector    = array_fill(0, (int) config('pipeline.embedding.dimensions', 1536), 0.0);
        $vector[0] = 1.0;

        return $vector;
    }
}
