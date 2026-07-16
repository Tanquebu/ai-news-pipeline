<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Services\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class EmbedDocumentChunksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $documentId) {}

    public function handle(EmbeddingService $service): void
    {
        $document = Document::findOrFail($this->documentId);

        // Solo i chunk ancora senza vettore: un retry riparte da dove
        // si era interrotto senza ricalcolare gli embedding già salvati.
        $chunks = $document->chunks()->whereNull('embedding')->get();

        foreach ($chunks as $chunk) {
            $embedding = $service->embedText($chunk->content);

            DB::table('document_chunks')
                ->where('id', $chunk->id)
                ->update(['embedding' => '[' . implode(',', $embedding) . ']']);
        }

        $document->update(['status' => 'embedded']);

        AssignDocumentToDossierJob::dispatch($document->id);
    }

    public function failed(?Throwable $exception): void
    {
        Document::where('id', $this->documentId)->update(['status' => 'failed']);
    }
}
