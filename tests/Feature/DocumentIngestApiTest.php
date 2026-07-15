<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ChunkDocumentJob;
use App\Models\Document;
use App\Models\IngestionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentIngestApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pipeline.api_token' => $this->token]);
        Storage::fake('local');
        Queue::fake();
    }

    // --- helpers ---

    private function authed(): static
    {
        return $this->withHeaders(['X-API-Token' => $this->token]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'source_system'    => 'intake',
            'source_record_id' => 'recABC123',
            'content_hash'     => hash('sha256', 'source content v1'),
            'url'              => 'https://example.com/article',
            'title'            => 'Example article',
            'summary'          => 'A short summary.',
            'full_text'        => 'The full text of the article.',
            'doc_type'         => 'article',
            'lang'             => 'en',
        ], $overrides);
    }

    // --- tests ---

    public function test_ingest_creates_document_event_and_raw_file(): void
    {
        $payload = $this->payload();

        $response = $this->authed()->postJson('/api/documents/ingest', $payload);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'ingested')
            ->assertJsonStructure(['ingestion_id', 'document_id', 'status']);

        $rawHash = hash('sha256', $payload['full_text']);
        $rawPath = 'rag/raw/' . substr($rawHash, 0, 2) . '/' . $rawHash . '.txt';

        $this->assertDatabaseHas('documents', [
            'id'       => $response->json('document_id'),
            'source'   => 'intake',
            'url'      => $payload['url'],
            'url_hash' => hash('sha256', $payload['url']),
            'title'    => $payload['title'],
            'summary'  => $payload['summary'],
            'doc_type' => 'article',
            'lang'     => 'en',
            'status'   => 'pending',
            'raw_path' => $rawPath,
            'raw_hash' => $rawHash,
            'mime'     => 'text/plain',
        ]);

        $this->assertDatabaseHas('ingestion_events', [
            'id'               => $response->json('ingestion_id'),
            'source_system'    => 'intake',
            'source_record_id' => 'recABC123',
            'content_hash'     => $payload['content_hash'],
            'document_id'      => $response->json('document_id'),
            'status'           => 'processed',
            'attempts'         => 1,
        ]);

        Storage::disk('local')->assertExists($rawPath);
        $this->assertSame($payload['full_text'], Storage::disk('local')->get($rawPath));
    }

    public function test_identical_retry_returns_duplicate_without_new_rows_or_files(): void
    {
        $payload = $this->payload();

        $first = $this->authed()->postJson('/api/documents/ingest', $payload);
        $first->assertStatus(202)->assertJsonPath('status', 'ingested');

        $second = $this->authed()->postJson('/api/documents/ingest', $payload);

        $second->assertStatus(202)
            ->assertJsonPath('status', 'duplicate')
            ->assertJsonPath('document_id', $first->json('document_id'))
            ->assertJsonPath('ingestion_id', $first->json('ingestion_id'));

        $this->assertSame(1, Document::count());
        $this->assertSame(1, IngestionEvent::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(1, IngestionEvent::first()->attempts);
    }

    public function test_new_content_hash_for_same_record_updates_existing_document(): void
    {
        $first = $this->authed()->postJson('/api/documents/ingest', $this->payload());
        $first->assertStatus(202);

        $updatedPayload = $this->payload([
            'content_hash' => hash('sha256', 'source content v2'),
            'full_text'    => 'The revised full text.',
            'summary'      => 'An updated summary.',
        ]);

        $second = $this->authed()->postJson('/api/documents/ingest', $updatedPayload);

        $second->assertStatus(202)
            ->assertJsonPath('status', 'updated')
            ->assertJsonPath('document_id', $first->json('document_id'));

        $this->assertSame(1, Document::count());
        $this->assertSame(2, IngestionEvent::count());

        $newRawHash = hash('sha256', 'The revised full text.');

        $this->assertDatabaseHas('documents', [
            'id'       => $first->json('document_id'),
            'summary'  => 'An updated summary.',
            'raw_hash' => $newRawHash,
            'status'   => 'pending',
        ]);

        Storage::disk('local')->assertExists(
            'rag/raw/' . substr($newRawHash, 0, 2) . '/' . $newRawHash . '.txt'
        );
    }

    public function test_failed_event_retry_reprocesses_the_same_event(): void
    {
        $payload = $this->payload();

        IngestionEvent::factory()->create([
            'source_system'    => $payload['source_system'],
            'source_record_id' => $payload['source_record_id'],
            'content_hash'     => $payload['content_hash'],
            'document_id'      => null,
            'status'           => 'failed',
            'attempts'         => 1,
            'error'            => 'boom',
        ]);

        $response = $this->authed()->postJson('/api/documents/ingest', $payload);

        $response->assertStatus(202)->assertJsonPath('status', 'ingested');

        $this->assertSame(1, IngestionEvent::count());
        $this->assertDatabaseHas('ingestion_events', [
            'id'          => $response->json('ingestion_id'),
            'document_id' => $response->json('document_id'),
            'status'      => 'processed',
            'attempts'    => 2,
            'error'       => null,
        ]);
    }

    public function test_same_url_from_different_record_updates_existing_document(): void
    {
        $first = $this->authed()->postJson('/api/documents/ingest', $this->payload());
        $first->assertStatus(202);

        $second = $this->authed()->postJson('/api/documents/ingest', $this->payload([
            'source_record_id' => 'recXYZ789',
            'content_hash'     => hash('sha256', 'other source content'),
        ]));

        $second->assertStatus(202)
            ->assertJsonPath('status', 'updated')
            ->assertJsonPath('document_id', $first->json('document_id'));

        $this->assertSame(1, Document::count());
        $this->assertSame(2, IngestionEvent::count());
    }

    public function test_validation_errors_return_422(): void
    {
        // title mancante
        $this->authed()->postJson('/api/documents/ingest', $this->payload(['title' => null]))
            ->assertStatus(422);

        // content_hash non sha256 hex
        $this->authed()->postJson('/api/documents/ingest', $this->payload(['content_hash' => 'not-a-hash']))
            ->assertStatus(422);

        // doc_type fuori enum
        $this->authed()->postJson('/api/documents/ingest', $this->payload(['doc_type' => 'video']))
            ->assertStatus(422);

        $this->assertSame(0, Document::count());
        $this->assertSame(0, IngestionEvent::count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/documents/ingest', $this->payload())
            ->assertUnauthorized();

        $this->assertSame(0, Document::count());
    }

    public function test_ingest_dispatches_chunk_job_for_ingested_and_updated_but_not_duplicate(): void
    {
        // Primo ingest → ingested → 1 dispatch.
        $first = $this->authed()->postJson('/api/documents/ingest', $this->payload());
        $first->assertStatus(202)->assertJsonPath('status', 'ingested');

        $documentId = $first->json('document_id');

        Queue::assertPushed(ChunkDocumentJob::class, 1);
        Queue::assertPushed(ChunkDocumentJob::class, fn (ChunkDocumentJob $job) => $job->documentId === $documentId);

        // Retry identico → duplicate → nessun nuovo dispatch.
        $this->authed()->postJson('/api/documents/ingest', $this->payload())
            ->assertStatus(202)
            ->assertJsonPath('status', 'duplicate');

        Queue::assertPushed(ChunkDocumentJob::class, 1);

        // Contenuto cambiato → updated → re-chunking da zero.
        $this->authed()->postJson('/api/documents/ingest', $this->payload([
            'content_hash' => hash('sha256', 'source content v2'),
            'full_text'    => 'The revised full text.',
        ]))
            ->assertStatus(202)
            ->assertJsonPath('status', 'updated');

        Queue::assertPushed(ChunkDocumentJob::class, 2);
    }

    public function test_ingest_without_full_text_creates_document_without_raw_file(): void
    {
        $payload = $this->payload();
        unset($payload['full_text']);

        $response = $this->authed()->postJson('/api/documents/ingest', $payload);

        $response->assertStatus(202)->assertJsonPath('status', 'ingested');

        $this->assertDatabaseHas('documents', [
            'id'       => $response->json('document_id'),
            'raw_path' => null,
            'raw_hash' => null,
            'mime'     => null,
            'status'   => 'pending',
        ]);

        $this->assertCount(0, Storage::disk('local')->allFiles());
    }
}
