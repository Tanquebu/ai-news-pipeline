<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\ChunkDocumentJob;
use App\Models\Document;
use App\Models\IngestionEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class IngestDocumentAction
{
    /**
     * Ingest a single document from an external source system, idempotently.
     *
     * Idempotency key: (source_system, source_record_id, content_hash),
     * enforced by the UNIQUE constraint on ingestion_events.
     *
     * @return array{status: string, ingestion_id: int, document_id: int|null}
     * @throws ValidationException
     */
    public function execute(array $payload): array
    {
        $data = Validator::make($payload, $this->rules())->validate();

        $data['content_hash'] = strtolower($data['content_hash']);

        try {
            return DB::transaction(fn (): array => $this->ingest($data));
        } catch (UniqueConstraintViolationException $e) {
            // Race su inserimenti concorrenti della stessa tripla: l'evento
            // vincente è già a DB, la richiesta è un duplicato a tutti gli effetti.
            $event = $this->findEvent($data);

            if ($event === null) {
                throw $e;
            }

            return [
                'status'       => 'duplicate',
                'ingestion_id' => $event->id,
                'document_id'  => $event->document_id,
            ];
        }
    }

    private function ingest(array $data): array
    {
        $event = $this->findEvent($data);

        if ($event !== null && $event->status === 'processed') {
            return [
                'status'       => 'duplicate',
                'ingestion_id' => $event->id,
                'document_id'  => $event->document_id,
            ];
        }

        $document = $this->resolveExistingDocument($data);
        $updated  = $document !== null;

        $attributes = $this->documentAttributes($data);

        if ($document !== null) {
            // Contenuto cambiato per un record già noto: si aggiorna il
            // documento esistente e lo status torna pending per il re-chunking.
            $document->update($attributes);
        } else {
            $document = Document::create($attributes);
        }

        if ($event !== null) {
            // Retry di una tripla failed/queued: si riusa lo stesso evento.
            $event->update([
                'document_id' => $document->id,
                'status'      => 'processed',
                'attempts'    => $event->attempts + 1,
                'error'       => null,
            ]);
        } else {
            $event = IngestionEvent::create([
                'source_system'    => $data['source_system'],
                'source_record_id' => $data['source_record_id'],
                'content_hash'     => $data['content_hash'],
                'document_id'      => $document->id,
                'status'           => 'processed',
                'attempts'         => 1,
            ]);
        }

        // Il document resta status=pending: il chunking avviene in coda. Il job
        // ha afterCommit=true, quindi parte solo dopo il commit della transazione.
        ChunkDocumentJob::dispatch($document->id);

        return [
            'status'       => $updated ? 'updated' : 'ingested',
            'ingestion_id' => $event->id,
            'document_id'  => $document->id,
        ];
    }

    private function rules(): array
    {
        return [
            'source_system'    => ['required', 'string'],
            'source_record_id' => ['required', 'string'],
            'content_hash'     => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'url'              => ['nullable', 'url'],
            'title'            => ['required', 'string'],
            'summary'          => ['nullable', 'string'],
            'full_text'        => ['nullable', 'string'],
            'doc_type'         => ['nullable', 'string', 'in:article,pdf,note'],
            'lang'             => ['nullable', 'string'],
            'source'           => ['nullable', 'string'],
        ];
    }

    private function findEvent(array $data): ?IngestionEvent
    {
        return IngestionEvent::where('source_system', $data['source_system'])
            ->where('source_record_id', $data['source_record_id'])
            ->where('content_hash', $data['content_hash'])
            ->first();
    }

    /**
     * Trova il documento da aggiornare quando il record sorgente è già noto:
     * prima l'ultimo ingestion_event dello stesso (source_system, source_record_id),
     * poi il match per url_hash (stesso URL arrivato da un record diverso).
     */
    private function resolveExistingDocument(array $data): ?Document
    {
        $previous = IngestionEvent::where('source_system', $data['source_system'])
            ->where('source_record_id', $data['source_record_id'])
            ->whereNotNull('document_id')
            ->latest('id')
            ->first();

        if ($previous !== null) {
            return $previous->document;
        }

        if (($data['url'] ?? null) !== null) {
            return Document::where('url_hash', hash('sha256', $data['url']))->first();
        }

        return null;
    }

    private function documentAttributes(array $data): array
    {
        $attributes = [
            'source'   => $data['source'] ?? $data['source_system'],
            'url'      => $data['url'] ?? null,
            'url_hash' => isset($data['url']) ? hash('sha256', $data['url']) : null,
            'title'    => $data['title'],
            'doc_type' => $data['doc_type'] ?? 'article',
            'lang'     => $data['lang'] ?? null,
            'summary'  => $data['summary'] ?? null,
            'status'   => 'pending',
            'raw_path' => null,
            'raw_hash' => null,
            'mime'     => null,
        ];

        if (($data['full_text'] ?? '') !== '') {
            [$attributes['raw_path'], $attributes['raw_hash']] = $this->storeRaw($data['full_text']);
            $attributes['mime'] = 'text/plain';
        }

        return $attributes;
    }

    /**
     * Raw storage content-addressable: il full_text non va a DB,
     * finisce su disco in rag/raw/<sha256[0:2]>/<sha256>.txt.
     *
     * @return array{0: string, 1: string} [raw_path, raw_hash]
     */
    private function storeRaw(string $fullText): array
    {
        $hash = hash('sha256', $fullText);
        $path = sprintf('rag/raw/%s/%s.txt', substr($hash, 0, 2), $hash);

        Storage::disk('local')->put($path, $fullText);

        return [$path, $hash];
    }
}
