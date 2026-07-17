<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\LLMClient;
use App\Models\Cluster;
use App\Models\NewsItem;
use App\Models\Report;
use App\Models\Tag;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClusterApiTest extends TestCase
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
        $this->getJson('/api/clusters')->assertStatus(401);
    }

    public function test_lists_clusters_ordered_by_score(): void
    {
        $this->makeCluster(score: 0.3);
        $this->makeCluster(score: 0.9);

        $response = $this->getJson('/api/clusters', $this->auth())->assertOk();

        $scores = collect($response->json('data'))->pluck('total_score')->all();
        $this->assertSame(array_values(array_unique($scores)), array_values($scores));
        $this->assertGreaterThan($scores[1], $scores[0]);
    }

    public function test_hides_old_clusters_by_default(): void
    {
        $this->makeCluster(score: 0.9, lastSeen: now()->subDays(20));
        $this->makeCluster(score: 0.5, lastSeen: now()->subDays(3));

        $this->getJson('/api/clusters', $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_all_bypasses_time_filter(): void
    {
        $this->makeCluster(score: 0.9, lastSeen: now()->subDays(20));
        $this->makeCluster(score: 0.5, lastSeen: now()->subDays(3));

        $this->getJson('/api/clusters?show_all=1', $this->auth())
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_since_parameter_overrides_default_window(): void
    {
        $this->makeCluster(score: 0.9, lastSeen: now()->subDays(20));
        $this->makeCluster(score: 0.5, lastSeen: now()->subDays(3));

        $since = now()->subDays(25)->toDateTimeString();
        $this->getJson("/api/clusters?since={$since}", $this->auth())
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_event_date_takes_priority_over_last_seen_at(): void
    {
        // old import but recent event → must appear
        $cluster = $this->makeCluster(score: 0.8, lastSeen: now()->subDays(20));
        $this->attachNewsItem($cluster, eventDate: now()->subDays(3)->toDateString());

        $this->getJson('/api/clusters', $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_old_event_date_hides_cluster_despite_recent_import(): void
    {
        // recent import but old event → must be hidden
        $cluster = $this->makeCluster(score: 0.8, lastSeen: now()->subDays(2));
        $this->attachNewsItem($cluster, eventDate: now()->subDays(20)->toDateString());

        $this->getJson('/api/clusters', $this->auth())
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_filters_by_score_min(): void
    {
        $this->makeCluster(score: 0.3);
        $this->makeCluster(score: 0.9);

        $this->getJson('/api/clusters?score_min=0.5', $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_filters_by_tag(): void
    {
        $c1 = $this->makeCluster(score: 0.8);
        $c2 = $this->makeCluster(score: 0.6);

        $mcp = Tag::where('slug', 'mcp')->first();
        $c1->tags()->attach($mcp->id);

        $this->getJson('/api/clusters?tag=mcp', $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_filters_by_source_ai(): void
    {
        $report1 = Report::create([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'gemini-2.5-pro',
            'payload'      => ['items' => []],
            'payload_hash' => str_repeat('a', 64),
            'ingested_at'  => now(),
        ]);
        $report2 = Report::create([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'gpt-4o',
            'payload'      => ['items' => []],
            'payload_hash' => str_repeat('b', 64),
            'ingested_at'  => now(),
        ]);

        $c1 = $this->makeCluster(score: 0.8);
        $c2 = $this->makeCluster(score: 0.7);

        NewsItem::create([
            'report_id'  => $report1->id,
            'cluster_id' => $c1->id,
            'section'    => 'technical',
            'title'      => 'Gemini item',
            'summary'    => 'Summary',
            'entities'   => [],
            'raw_tags'   => [],
        ]);
        NewsItem::create([
            'report_id'  => $report2->id,
            'cluster_id' => $c2->id,
            'section'    => 'technical',
            'title'      => 'GPT item',
            'summary'    => 'Summary',
            'entities'   => [],
            'raw_tags'   => [],
        ]);

        $this->getJson('/api/clusters?source_ai=gemini-2.5-pro&show_all=1', $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $c1->id);
    }

    public function test_generators_endpoint_returns_distinct_sorted_values(): void
    {
        Report::create([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'gpt-4o',
            'payload'      => ['items' => []],
            'payload_hash' => str_repeat('c', 64),
            'ingested_at'  => now(),
        ]);
        Report::create([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'gemini-2.5-pro',
            'payload'      => ['items' => []],
            'payload_hash' => str_repeat('d', 64),
            'ingested_at'  => now(),
        ]);
        Report::create([
            'report_date'  => '2026-05-16',
            'source_ai'    => 'gpt-4o',
            'payload'      => ['items' => []],
            'payload_hash' => str_repeat('f', 64),
            'ingested_at'  => now(),
        ]);

        $response = $this->getJson('/api/reports/generators', $this->auth())
            ->assertOk();

        $this->assertSame(['gemini-2.5-pro', 'gpt-4o'], $response->json());
    }

    public function test_show_returns_cluster_with_items_and_publications(): void
    {
        $cluster = $this->makeClusterWithItem();

        $response = $this->getJson("/api/clusters/{$cluster->id}", $this->auth())->assertOk();

        $this->assertArrayHasKey('cluster', $response->json());
        $this->assertArrayHasKey('publications', $response->json());
    }

    public function test_archive_sets_status_to_archived(): void
    {
        $cluster = $this->makeCluster(score: 0.8);

        $this->postJson("/api/clusters/{$cluster->id}/archive", [], $this->auth())
            ->assertOk()
            ->assertJson(['status' => 'archived']);

        $this->assertSame('archived', $cluster->fresh()->status);
    }

    public function test_archive_returns_422_if_already_archived(): void
    {
        $cluster = $this->makeCluster(score: 0.8);
        $cluster->update(['status' => 'archived']);

        $this->postJson("/api/clusters/{$cluster->id}/archive", [], $this->auth())
            ->assertStatus(422);
    }

    public function test_generate_linkedin_creates_drafts(): void
    {
        $this->bindFakeLLM(['short' => 'Short', 'medium' => 'Medium', 'opinion' => 'Opinion', 'large' => 'Large']);

        $cluster = $this->makeCluster(score: 0.8);

        $this->postJson("/api/clusters/{$cluster->id}/generate/linkedin", [], $this->auth())
            ->assertStatus(201)
            ->assertJsonCount(4);

        $this->assertDatabaseCount('publications', 4);
    }

    public function test_generate_article_returns_422_for_ineligible_cluster(): void
    {
        $cluster = $this->makeCluster(score: 0.8); // no news items

        $this->postJson("/api/clusters/{$cluster->id}/generate/article", [], $this->auth())
            ->assertStatus(422);
    }

    // --- helpers ---

    private function makeCluster(float $score, ?\DateTimeInterface $lastSeen = null): Cluster
    {
        return Cluster::create([
            'canonical_title' => 'Cluster ' . uniqid(),
            'first_seen_at'   => now(),
            'last_seen_at'    => $lastSeen ?? now(),
            'consensus_count' => 1,
            'total_score'     => $score,
            'status'          => 'active',
        ]);
    }

    private function attachNewsItem(Cluster $cluster, string $eventDate): void
    {
        $report = Report::firstOrCreate(
            ['payload_hash' => str_repeat('e', 64)],
            [
                'report_date' => '2026-05-15',
                'source_ai'   => 'test',
                'payload'     => ['items' => []],
                'ingested_at' => now(),
            ]
        );

        NewsItem::create([
            'report_id'  => $report->id,
            'cluster_id' => $cluster->id,
            'section'    => 'technical',
            'title'      => 'Item',
            'summary'    => 'Summary',
            'entities'   => [],
            'raw_tags'   => [],
            'event_date' => $eventDate,
        ]);
    }

    private function makeClusterWithItem(): Cluster
    {
        $report = Report::create([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'test',
            'payload'      => ['items' => []],
            'payload_hash' => str_repeat('i', 64),
            'ingested_at'  => now(),
        ]);

        $cluster = $this->makeCluster(0.7);

        NewsItem::create([
            'report_id'  => $report->id,
            'cluster_id' => $cluster->id,
            'section'    => 'strategic',
            'title'      => 'Item',
            'summary'    => 'Summary',
            'entities'   => [],
            'raw_tags'   => [],
        ]);

        return $cluster;
    }

    private function bindFakeLLM(array $response): void
    {
        $json = json_encode($response);

        $this->app->bind(LLMClient::class, fn () => new class ($json) implements LLMClient {
            public function __construct(private readonly string $json) {}

            public function complete(string $prompt, int $maxTokens = 1024): string
            {
                return $this->json;
            }
        });
    }
}
