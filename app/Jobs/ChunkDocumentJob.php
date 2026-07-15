<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ChunkDocumentJob implements ShouldQueue
{
    use Queueable;

    /** Target chunk size in estimated tokens (~4 chars per token). */
    private const CHUNK_SIZE_TOKENS = 900;

    /** Overlap between consecutive chunks, in estimated tokens. */
    private const OVERLAP_TOKENS = 150;

    private const CHARS_PER_TOKEN = 4;

    public int $tries = 3;

    public function __construct(public readonly int $documentId)
    {
        // Il dispatch avviene dentro la transazione di IngestDocumentAction:
        // il job non deve partire prima del commit.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $document = Document::findOrFail($this->documentId);

        $text = trim($this->sourceText($document));

        if ($text === '') {
            throw new RuntimeException("Document {$document->id} has no text to chunk.");
        }

        $chunks = $this->split($text);

        // Idempotente: un rilancio rimpiazza integralmente i chunk esistenti.
        // La UNIQUE(document_id, chunk_index) protegge da esecuzioni concorrenti.
        DB::transaction(function () use ($document, $chunks): void {
            $document->chunks()->delete();

            foreach ($chunks as $index => $content) {
                DocumentChunk::create([
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'content'     => $content,
                    'token_count' => (int) ceil(mb_strlen($content) / self::CHARS_PER_TOKEN),
                ]);
            }

            $document->update(['status' => 'chunked']);
        });

        EmbedDocumentChunksJob::dispatch($document->id);
    }

    public function failed(?Throwable $exception): void
    {
        Document::where('id', $this->documentId)->update(['status' => 'failed']);
    }

    /**
     * Il full_text vive su storage locale (rag/raw/, content-addressable);
     * per i documenti ingestati senza full_text si ripiega sul summary.
     */
    private function sourceText(Document $document): string
    {
        if ($document->raw_path !== null && Storage::disk('local')->exists($document->raw_path)) {
            return Storage::disk('local')->get($document->raw_path) ?? '';
        }

        return $document->summary ?? '';
    }

    /**
     * Sliding window in caratteri (stima ~4 char/token) con overlap,
     * spezzando preferibilmente su whitespace per non troncare parole.
     *
     * @return list<string>
     */
    private function split(string $text): array
    {
        $chunkChars   = self::CHUNK_SIZE_TOKENS * self::CHARS_PER_TOKEN;
        $overlapChars = self::OVERLAP_TOKENS * self::CHARS_PER_TOKEN;

        $length = mb_strlen($text);

        if ($length <= $chunkChars) {
            return [$text];
        }

        $chunks = [];
        $start  = 0;

        while (true) {
            if ($length - $start <= $chunkChars) {
                $chunks[] = mb_substr($text, $start);
                break;
            }

            $slice = mb_substr($text, $start, $chunkChars);

            // Arretra all'ultimo whitespace del pezzo per non spezzare una
            // parola, ma mai sotto metà chunk (garantisce avanzamento).
            $breakAt = $this->lastWhitespacePosition($slice);

            if ($breakAt !== null && $breakAt > intdiv($chunkChars, 2)) {
                $slice = mb_substr($slice, 0, $breakAt);
            }

            $chunks[] = $slice;

            // Il chunk successivo riparte overlapChars prima della fine del
            // precedente: len(slice) >= chunkChars/2 > overlapChars, quindi
            // lo start avanza sempre.
            $start += mb_strlen($slice) - $overlapChars;
        }

        return $chunks;
    }

    private function lastWhitespacePosition(string $slice): ?int
    {
        if (preg_match('/^(.*\s)\S*$/us', $slice, $matches) === 1) {
            return mb_strlen($matches[1]);
        }

        return null;
    }
}
