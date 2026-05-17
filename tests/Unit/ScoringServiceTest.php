<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Cluster;
use App\Models\NewsItem;
use App\Models\Report;
use App\Models\Tag;
use App\Services\ScoringService;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TagSeeder::class);
        $this->service = new ScoringService();
    }

    public function test_calculates_total_score_from_weights(): void
    {
        [$cluster, $item] = $this->makeClusterWithItem(importance: 5);

        $mcpTag = Tag::where('slug', 'mcp')->first();
        $cluster->tags()->attach($mcpTag->id);
        $cluster->update(['novelty_score' => 1.0, 'consensus_count' => 10]);

        $this->service->updateScore($cluster);
        $cluster->refresh();

        // All inputs at max → total_score should be 1.0
        $this->assertEqualsWithDelta(1.0, $cluster->total_score, 0.001);
    }

    public function test_uses_fallback_3_for_null_importance(): void
    {
        [$cluster, $item] = $this->makeClusterWithItem(importance: null);

        $this->service->updateScore($cluster);
        $cluster->refresh();

        // importance_avg should default to 3 (mid-scale)
        $this->assertEqualsWithDelta(3.0, $cluster->importance_avg, 0.001);
    }

    public function test_topic_match_is_fraction_of_interest_tags(): void
    {
        [$cluster] = $this->makeClusterWithItem(importance: 3);

        $mcp  = Tag::where('slug', 'mcp')->first();
        $fund = Tag::where('slug', 'funding')->first();
        $cluster->tags()->attach([$mcp->id, $fund->id]);

        // Only mcp is in default interest tags (mcp, agentic-frameworks, coding-tools)
        $this->service->updateScore($cluster);
        $cluster->refresh();

        $this->assertEqualsWithDelta(0.5, $cluster->topic_match_score, 0.001);
    }

    public function test_zero_score_for_empty_cluster(): void
    {
        $cluster = Cluster::create([
            'canonical_title' => 'Empty',
            'first_seen_at'   => now(),
            'last_seen_at'    => now(),
            'consensus_count' => 1,
            'status'          => 'active',
        ]);

        $this->service->updateScore($cluster);
        $cluster->refresh();

        $this->assertNotNull($cluster->total_score);
        $this->assertGreaterThanOrEqual(0.0, $cluster->total_score);
    }

    // --- helpers ---

    private function makeClusterWithItem(?int $importance): array
    {
        $report = Report::create([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'test',
            'payload'      => ['items' => []],
            'payload_hash' => str_repeat('e', 64),
            'ingested_at'  => now(),
        ]);

        $cluster = Cluster::create([
            'canonical_title' => 'Test Cluster',
            'first_seen_at'   => now(),
            'last_seen_at'    => now(),
            'consensus_count' => 1,
            'status'          => 'active',
        ]);

        $item = NewsItem::create([
            'report_id'             => $report->id,
            'cluster_id'            => $cluster->id,
            'section'               => 'strategic',
            'title'                 => 'Test',
            'summary'               => 'Test summary',
            'entities'              => [],
            'raw_tags'              => [],
            'importance_self_rated' => $importance,
        ]);

        return [$cluster, $item];
    }
}
