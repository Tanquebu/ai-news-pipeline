<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\NewsItem;
use App\Models\Report;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NewsItemControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TagSeeder::class);
        config(['pipeline.api_token' => 'test-token']);
    }

    private function auth(): array
    {
        return ['X-API-Token' => 'test-token'];
    }

    public function test_returns_401_without_token(): void
    {
        $this->getJson('/api/news-items')->assertStatus(401);
    }

    public function test_returns_empty_data_when_db_is_empty(): void
    {
        $this->getJson('/api/news-items', $this->auth())
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_returns_all_items_without_filters(): void
    {
        $this->makeNewsItem(title: 'First item');
        $this->makeNewsItem(title: 'Second item');

        $response = $this->getJson('/api/news-items', $this->auth())->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_filters_by_query_matching_title(): void
    {
        $this->makeNewsItem(title: 'GPT-5 released by OpenAI');
        $this->makeNewsItem(title: 'Claude 4 announced');

        $response = $this->getJson('/api/news-items?query=GPT', $this->auth())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('GPT-5 released by OpenAI', $response->json('data.0.title'));
    }

    public function test_filters_by_query_matching_summary(): void
    {
        $this->makeNewsItem(title: 'AI news', summary: 'OpenAI released a new model');
        $this->makeNewsItem(title: 'Other news', summary: 'Unrelated content here');

        $response = $this->getJson('/api/news-items?query=openai', $this->auth())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('AI news', $response->json('data.0.title'));
    }

    public function test_query_filter_is_case_insensitive(): void
    {
        $this->makeNewsItem(title: 'MACHINE LEARNING update');
        $this->makeNewsItem(title: 'Unrelated item');

        $response = $this->getJson('/api/news-items?query=machine+learning', $this->auth())->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_filters_by_since_date(): void
    {
        $this->makeNewsItemWithDate(title: 'Old item', createdAt: '2025-01-01 00:00:00');
        $this->makeNewsItemWithDate(title: 'New item', createdAt: '2025-06-01 00:00:00');

        $response = $this->getJson('/api/news-items?since=2025-03-01', $this->auth())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('New item', $response->json('data.0.title'));
    }

    public function test_filters_by_section(): void
    {
        $this->makeNewsItem(title: 'Strategic item', section: 'strategic');
        $this->makeNewsItem(title: 'Technical item', section: 'technical');

        $response = $this->getJson('/api/news-items?section=strategic', $this->auth())->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Strategic item', $response->json('data.0.title'));
    }

    // --- helpers ---

    private function makeReport(): Report
    {
        static $counter = 0;
        $counter++;

        // Generate a deterministic 64-char hex hash from the counter
        $hash = hash('sha256', 'report-' . $counter);

        return Report::firstOrCreate(
            ['payload_hash' => $hash],
            [
                'report_date' => '2026-05-15',
                'source_ai'   => 'test-ai',
                'payload'     => ['items' => []],
                'ingested_at' => now(),
            ],
        );
    }

    private function makeNewsItem(
        string $title = 'Test item',
        string $summary = 'Test summary',
        string $section = 'strategic',
    ): NewsItem {
        return NewsItem::create([
            'report_id' => $this->makeReport()->id,
            'section'   => $section,
            'title'     => $title,
            'summary'   => $summary,
            'entities'  => [],
            'raw_tags'  => [],
        ]);
    }

    private function makeNewsItemWithDate(string $title, string $createdAt): NewsItem
    {
        $item = $this->makeNewsItem(title: $title);
        DB::table('news_items')->where('id', $item->id)->update(['created_at' => $createdAt]);

        return $item;
    }
}
