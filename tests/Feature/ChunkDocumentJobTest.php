<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ChunkDocumentJob;
use App\Jobs\EmbedDocumentChunksJob;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ChunkDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake([EmbedDocumentChunksJob::class]);
    }

    public function test_long_text_produces_overlapping_chunks_with_progressive_indices(): void
    {
        // ~9000 caratteri: ben oltre la finestra da ~3600 char (900 token stimati).
        $text     = $this->longText(9000);
        $document = $this->createDocumentWithRaw($text);

        (new ChunkDocumentJob($document->id))->handle();

        $chunks = $document->chunks()->get();

        $this->assertGreaterThanOrEqual(2, $chunks->count());
        $this->assertSame(range(0, $chunks->count() - 1), $chunks->pluck('chunk_index')->all());

        foreach ($chunks as $chunk) {
            $this->assertNotSame('', trim($chunk->content));
            $this->assertGreaterThan(0, $chunk->token_count);
        }

        // Overlap: il chunk successivo riparte 150 token stimati (600 char)
        // prima della fine del precedente, quindi gli ultimi 600 caratteri
        // del chunk 0 coincidono con i primi 600 del chunk 1.
        $overlapChars = 600;
        $this->assertSame(
            mb_substr($chunks[0]->content, -$overlapChars),
            mb_substr($chunks[1]->content, 0, $overlapChars),
            'Expected chunk 0 to end with the beginning of chunk 1 (overlap).'
        );

        $this->assertSame('chunked', $document->fresh()->status);

        Bus::assertDispatched(EmbedDocumentChunksJob::class, fn ($job) => $job->documentId === $document->id);
    }

    public function test_short_text_produces_a_single_chunk(): void
    {
        $text     = 'A short article body that fits in a single chunk.';
        $document = $this->createDocumentWithRaw($text);

        (new ChunkDocumentJob($document->id))->handle();

        $chunks = $document->chunks()->get();

        $this->assertCount(1, $chunks);
        $this->assertSame(0, $chunks[0]->chunk_index);
        $this->assertSame($text, $chunks[0]->content);

        $this->assertSame('chunked', $document->fresh()->status);

        Bus::assertDispatched(EmbedDocumentChunksJob::class, fn ($job) => $job->documentId === $document->id);
    }

    public function test_relaunch_replaces_chunks_without_duplicating_them(): void
    {
        $document = $this->createDocumentWithRaw($this->longText(9000));

        (new ChunkDocumentJob($document->id))->handle();
        $firstRun = $document->chunks()->get();

        (new ChunkDocumentJob($document->id))->handle();
        $secondRun = $document->chunks()->get();

        $this->assertSame($firstRun->count(), $secondRun->count());
        $this->assertSame(range(0, $secondRun->count() - 1), $secondRun->pluck('chunk_index')->all());
        $this->assertSame($firstRun->pluck('content')->all(), $secondRun->pluck('content')->all());

        $this->assertSame('chunked', $document->fresh()->status);
    }

    public function test_document_without_raw_file_falls_back_to_summary(): void
    {
        $document = Document::factory()->create([
            'raw_path' => null,
            'raw_hash' => null,
            'summary'  => 'Only a summary is available.',
            'status'   => 'pending',
        ]);

        (new ChunkDocumentJob($document->id))->handle();

        $chunks = $document->chunks()->get();

        $this->assertCount(1, $chunks);
        $this->assertSame('Only a summary is available.', $chunks[0]->content);
        $this->assertSame('chunked', $document->fresh()->status);
    }

    public function test_document_without_any_text_throws(): void
    {
        $document = Document::factory()->create([
            'raw_path' => null,
            'raw_hash' => null,
            'summary'  => null,
            'status'   => 'pending',
        ]);

        $this->expectException(RuntimeException::class);

        (new ChunkDocumentJob($document->id))->handle();
    }

    public function test_failed_marks_document_as_failed(): void
    {
        $document = Document::factory()->create(['status' => 'pending']);

        (new ChunkDocumentJob($document->id))->failed(new RuntimeException('boom'));

        $this->assertSame('failed', $document->fresh()->status);
    }

    // --- helpers ---

    private function createDocumentWithRaw(string $text): Document
    {
        $hash = hash('sha256', $text);
        $path = 'rag/raw/' . substr($hash, 0, 2) . '/' . $hash . '.txt';

        Storage::disk('local')->put($path, $text);

        return Document::factory()->create([
            'raw_path' => $path,
            'raw_hash' => $hash,
            'mime'     => 'text/plain',
            'status'   => 'pending',
        ]);
    }

    private function longText(int $minLength): string
    {
        $text = '';
        $i    = 0;

        while (mb_strlen($text) < $minLength) {
            $text .= 'word' . $i . ' ';
            $i++;
        }

        return trim($text);
    }
}
