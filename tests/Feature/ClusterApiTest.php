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

    public function test_show_returns_cluster_with_items_and_publications(): void
    {
        $cluster = $this->makeClusterWithItem();

        $response = $this->getJson("/api/clusters/{$cluster->id}", $this->auth())->assertOk();

        $this->assertArrayHasKey('cluster', $response->json());
        $this->assertArrayHasKey('publications', $response->json());
    }

    public function test_generate_linkedin_creates_drafts(): void
    {
        $this->bindFakeLLM(['short' => 'Short', 'medium' => 'Medium', 'opinion' => 'Opinion']);

        $cluster = $this->makeCluster(score: 0.8);

        $this->postJson("/api/clusters/{$cluster->id}/generate/linkedin", [], $this->auth())
            ->assertStatus(201)
            ->assertJsonCount(3);

        $this->assertDatabaseCount('publications', 3);
    }

    public function test_generate_article_returns_422_for_ineligible_cluster(): void
    {
        $cluster = $this->makeCluster(score: 0.8); // no news items

        $this->postJson("/api/clusters/{$cluster->id}/generate/article", [], $this->auth())
            ->assertStatus(422);
    }

    // --- helpers ---

    private function makeCluster(float $score): Cluster
    {
        return Cluster::create([
            'canonical_title' => 'Cluster ' . uniqid(),
            'first_seen_at'   => now(),
            'last_seen_at'    => now(),
            'consensus_count' => 1,
            'total_score'     => $score,
            'status'          => 'active',
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
