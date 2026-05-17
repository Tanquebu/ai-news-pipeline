<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ClusterNewsItemJob;
use App\Jobs\EmbedNewsItemJob;
use App\Models\NewsItem;
use App\Models\Report;
use App\Services\EmbeddingService;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmbedNewsItemJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TagSeeder::class);
    }

    public function test_job_stores_embedding_and_dispatches_cluster_job(): void
    {
        Bus::fake([ClusterNewsItemJob::class]);

        $embedding = $this->makeEmbedding(1.0, 0);

        $service = $this->createMock(EmbeddingService::class);
        $service->method('embedNewsItem')->willReturn($embedding);
        $this->app->instance(EmbeddingService::class, $service);

        $item = $this->createNewsItem();

        (new EmbedNewsItemJob($item->id))->handle($service);

        $stored = DB::scalar('SELECT embedding::text FROM news_items WHERE id = ?', [$item->id]);
        $this->assertNotNull($stored);

        Bus::assertDispatched(ClusterNewsItemJob::class, fn ($job) => $job->newsItemId === $item->id);
    }

    public function test_job_fails_if_news_item_not_found(): void
    {
        $service = $this->createMock(EmbeddingService::class);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        (new EmbedNewsItemJob(99999))->handle($service);
    }

    // --- helpers ---

    private function createNewsItem(): NewsItem
    {
        $report = Report::create([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'claude-opus-4-7',
            'payload'      => ['report_date' => '2026-05-15', 'source_ai' => 'claude-opus-4-7', 'items' => []],
            'payload_hash' => str_repeat('a', 64),
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

    /** @return float[] */
    private function makeEmbedding(float $first, float $second): array
    {
        $vec    = array_fill(0, (int) config('pipeline.embedding.dimensions', 1536), 0.0);
        $vec[0] = $first;
        $vec[1] = $second;

        return $vec;
    }
}
