<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ChunkDocumentJob;
use App\Jobs\EmbedNewsItemJob;
use App\Models\Document;
use App\Models\NewsItem;
use App\Models\NewsItemSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ingest documentale con category: solo category=news genera anche un
 * news_item collegato al document e agganciato al flusso embed/cluster.
 */
class DocumentIngestNewsItemTest extends TestCase
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
            'source_record_id' => 'recNEWS001',
            'content_hash'     => hash('sha256', 'news content v1'),
            'url'              => 'https://example.com/news-article',
            'title'            => 'Big AI announcement',
            'summary'          => 'Something notable happened.',
            'full_text'        => 'The full text of the news article.',
            'doc_type'         => 'article',
            'lang'             => 'en',
            'category'         => 'news',
        ], $overrides);
    }

    // --- tests ---

    public function test_ingest_with_category_news_creates_linked_news_item(): void
    {
        $response = $this->authed()->postJson('/api/documents/ingest', $this->payload());

        $response->assertStatus(202)->assertJsonPath('status', 'ingested');

        $document = Document::findOrFail($response->json('document_id'));

        $this->assertNotNull($document->news_item_id);
        $this->assertSame(1, NewsItem::count());

        $this->assertDatabaseHas('news_items', [
            'id'        => $document->news_item_id,
            'report_id' => null,
            'section'   => 'strategic',
            'title'     => 'Big AI announcement',
            'summary'   => 'Something notable happened.',
        ]);

        $this->assertDatabaseHas('news_item_sources', [
            'news_item_id' => $document->news_item_id,
            'name'         => 'intake',
            'url'          => 'https://example.com/news-article',
            'position'     => 0,
        ]);
    }

    public function test_ingest_news_dispatches_embed_news_item_job_alongside_chunk_job(): void
    {
        $response = $this->authed()->postJson('/api/documents/ingest', $this->payload());

        $response->assertStatus(202);

        $newsItemId = Document::findOrFail($response->json('document_id'))->news_item_id;

        Queue::assertPushed(ChunkDocumentJob::class, 1);
        Queue::assertPushed(EmbedNewsItemJob::class, 1);
        Queue::assertPushed(EmbedNewsItemJob::class, fn (EmbedNewsItemJob $job) => $job->newsItemId === $newsItemId);
    }

    public function test_ingest_with_non_news_category_creates_no_news_item(): void
    {
        $response = $this->authed()->postJson('/api/documents/ingest', $this->payload(['category' => 'tool']));

        $response->assertStatus(202)->assertJsonPath('status', 'ingested');

        $this->assertNull(Document::findOrFail($response->json('document_id'))->news_item_id);
        $this->assertSame(0, NewsItem::count());
        Queue::assertNotPushed(EmbedNewsItemJob::class);
    }

    public function test_ingest_without_category_creates_no_news_item(): void
    {
        $payload = $this->payload();
        unset($payload['category']);

        $response = $this->authed()->postJson('/api/documents/ingest', $payload);

        $response->assertStatus(202)->assertJsonPath('status', 'ingested');

        $this->assertNull(Document::findOrFail($response->json('document_id'))->news_item_id);
        $this->assertSame(0, NewsItem::count());
        Queue::assertNotPushed(EmbedNewsItemJob::class);
    }

    public function test_duplicate_retry_on_news_creates_no_second_news_item(): void
    {
        $first = $this->authed()->postJson('/api/documents/ingest', $this->payload());
        $first->assertStatus(202)->assertJsonPath('status', 'ingested');

        $second = $this->authed()->postJson('/api/documents/ingest', $this->payload());

        $second->assertStatus(202)
            ->assertJsonPath('status', 'duplicate')
            ->assertJsonPath('document_id', $first->json('document_id'));

        $this->assertSame(1, NewsItem::count());
        $this->assertSame(1, NewsItemSource::count());
        Queue::assertPushed(EmbedNewsItemJob::class, 1);
    }

    public function test_updated_news_document_reuses_news_item_without_duplicates(): void
    {
        $first = $this->authed()->postJson('/api/documents/ingest', $this->payload());
        $first->assertStatus(202);

        $firstNewsItemId = Document::findOrFail($first->json('document_id'))->news_item_id;

        $second = $this->authed()->postJson('/api/documents/ingest', $this->payload([
            'content_hash' => hash('sha256', 'news content v2'),
            'title'        => 'Big AI announcement (revised)',
            'summary'      => 'Revised summary.',
            'full_text'    => 'The revised full text.',
        ]));

        $second->assertStatus(202)
            ->assertJsonPath('status', 'updated')
            ->assertJsonPath('document_id', $first->json('document_id'));

        $this->assertSame(1, NewsItem::count());
        $this->assertSame(1, NewsItemSource::count());

        $this->assertDatabaseHas('news_items', [
            'id'      => $firstNewsItemId,
            'title'   => 'Big AI announcement (revised)',
            'summary' => 'Revised summary.',
        ]);

        // Contenuto cambiato: il news_item va ri-embeddato (il clustering
        // esistente resta idempotente su cluster_id già assegnato).
        Queue::assertPushed(EmbedNewsItemJob::class, 2);
    }

    public function test_update_that_turns_document_into_news_creates_and_links_news_item(): void
    {
        // Primo ingest senza category: solo document, nessun news_item.
        $payload = $this->payload();
        unset($payload['category']);

        $first = $this->authed()->postJson('/api/documents/ingest', $payload);
        $first->assertStatus(202)->assertJsonPath('status', 'ingested');
        $this->assertSame(0, NewsItem::count());

        // Stesso record ripushato come news con contenuto cambiato.
        $second = $this->authed()->postJson('/api/documents/ingest', $this->payload([
            'content_hash' => hash('sha256', 'news content v2'),
        ]));

        $second->assertStatus(202)
            ->assertJsonPath('status', 'updated')
            ->assertJsonPath('document_id', $first->json('document_id'));

        $this->assertSame(1, NewsItem::count());
        $this->assertNotNull(Document::findOrFail($first->json('document_id'))->news_item_id);
        Queue::assertPushed(EmbedNewsItemJob::class, 1);
    }

    public function test_news_without_summary_falls_back_to_title(): void
    {
        $payload = $this->payload();
        unset($payload['summary']);

        $response = $this->authed()->postJson('/api/documents/ingest', $payload);

        $response->assertStatus(202);

        $this->assertDatabaseHas('news_items', [
            'title'   => 'Big AI announcement',
            'summary' => 'Big AI announcement',
        ]);
    }
}
