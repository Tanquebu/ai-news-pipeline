<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\IngestionEvent;
use App\Models\NewsItem;
use App\Models\Report;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_create_document_tables(): void
    {
        $this->assertTrue(Schema::hasTable('documents'));
        $this->assertTrue(Schema::hasTable('document_chunks'));
        $this->assertTrue(Schema::hasTable('ingestion_events'));
        $this->assertTrue(Schema::hasColumn('document_chunks', 'embedding'));
    }

    public function test_document_belongs_to_news_item_and_has_many_chunks(): void
    {
        $newsItem = $this->createNewsItem();

        $document = Document::factory()->create(['news_item_id' => $newsItem->id]);

        DocumentChunk::factory()->create(['document_id' => $document->id, 'chunk_index' => 1]);
        DocumentChunk::factory()->create(['document_id' => $document->id, 'chunk_index' => 0]);

        $this->assertTrue($document->newsItem->is($newsItem));
        $this->assertCount(2, $document->chunks);
        // hasMany ordinata per chunk_index
        $this->assertSame([0, 1], $document->chunks->pluck('chunk_index')->all());
        $this->assertTrue($document->chunks->first()->document->is($document));
    }

    public function test_ingestion_event_belongs_to_document(): void
    {
        $document = Document::factory()->create();
        $event    = IngestionEvent::factory()->create(['document_id' => $document->id]);

        $this->assertTrue($event->document->is($document));
    }

    public function test_chunk_embedding_can_be_stored_as_vector(): void
    {
        $chunk = DocumentChunk::factory()->create();

        $vector = array_fill(0, (int) config('pipeline.embedding.dimensions', 1536), 0.0);
        $vector[0] = 1.0;

        DB::table('document_chunks')
            ->where('id', $chunk->id)
            ->update(['embedding' => '[' . implode(',', $vector) . ']']);

        $stored = DB::scalar('SELECT embedding::text FROM document_chunks WHERE id = ?', [$chunk->id]);
        $this->assertNotNull($stored);
    }

    public function test_ingestion_events_unique_on_source_and_content_hash(): void
    {
        $attributes = [
            'source_system'    => 'intake',
            'source_record_id' => 'recABC123',
            'content_hash'     => str_repeat('a', 64),
        ];

        IngestionEvent::factory()->create($attributes);

        $this->expectException(QueryException::class);

        IngestionEvent::factory()->create($attributes);
    }

    public function test_document_chunks_unique_on_document_and_chunk_index(): void
    {
        $document = Document::factory()->create();

        DocumentChunk::factory()->create(['document_id' => $document->id, 'chunk_index' => 0]);

        $this->expectException(QueryException::class);

        DocumentChunk::factory()->create(['document_id' => $document->id, 'chunk_index' => 0]);
    }

    public function test_deleting_document_cascades_to_chunks(): void
    {
        $document = Document::factory()->create();
        $chunk    = DocumentChunk::factory()->create(['document_id' => $document->id]);

        $document->delete();

        $this->assertDatabaseMissing('document_chunks', ['id' => $chunk->id]);
    }

    public function test_deleting_news_item_nulls_document_reference(): void
    {
        $newsItem = $this->createNewsItem();
        $document = Document::factory()->create(['news_item_id' => $newsItem->id]);

        $newsItem->delete();

        $this->assertNull($document->fresh()->news_item_id);
    }

    public function test_deleting_document_nulls_ingestion_event_reference(): void
    {
        $document = Document::factory()->create();
        $event    = IngestionEvent::factory()->create(['document_id' => $document->id]);

        $document->delete();

        $this->assertNull($event->fresh()->document_id);
    }

    // --- helpers ---

    private function createNewsItem(): NewsItem
    {
        $report = Report::create([
            'report_date'  => '2026-07-15',
            'source_ai'    => 'claude-opus-4-7',
            'payload'      => ['report_date' => '2026-07-15', 'source_ai' => 'claude-opus-4-7', 'items' => []],
            'payload_hash' => str_repeat('b', 64),
            'ingested_at'  => now(),
        ]);

        return NewsItem::create([
            'report_id' => $report->id,
            'section'   => 'strategic',
            'title'     => 'Test title',
            'summary'   => 'Test summary',
            'entities'  => [],
            'raw_tags'  => [],
        ]);
    }
}
