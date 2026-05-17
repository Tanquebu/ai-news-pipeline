<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\LLMClient;
use App\Jobs\SynthesizeClusterJob;
use App\Models\Cluster;
use App\Models\NewsItem;
use App\Models\Report;
use App\Models\TagProposal;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SynthesizeClusterJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TagSeeder::class);
    }

    public function test_updates_cluster_canonical_fields_and_tags(): void
    {
        $this->bindFakeLLM([
            'canonical_title'   => 'Synthesized Title',
            'canonical_summary' => 'Sintesi del cluster.',
            'tags'              => ['mcp', 'funding'],
            'tag_proposals'     => [],
            'novelty_score'     => 0.8,
        ]);

        $cluster = $this->makeCluster();

        (new SynthesizeClusterJob($cluster->id))->handle(
            $this->app->make(LLMClient::class),
            $this->app->make(\App\Services\ScoringService::class),
        );

        $cluster->refresh();

        $this->assertSame('Synthesized Title', $cluster->canonical_title);
        $this->assertSame('Sintesi del cluster.', $cluster->canonical_summary);
        $this->assertEqualsWithDelta(0.8, $cluster->novelty_score, 0.001);
        $this->assertNotNull($cluster->total_score);

        $tagSlugs = $cluster->tags->pluck('slug')->sort()->values()->all();
        $this->assertSame(['funding', 'mcp'], $tagSlugs);
    }

    public function test_creates_tag_proposals_for_unknown_tags(): void
    {
        $this->bindFakeLLM([
            'canonical_title'   => 'Title',
            'canonical_summary' => 'Summary.',
            'tags'              => [],
            'tag_proposals'     => [
                ['slug' => 'quantum-computing', 'reason' => 'Emerging topic not in taxonomy'],
            ],
            'novelty_score'     => 0.5,
        ]);

        $cluster = $this->makeCluster();

        (new SynthesizeClusterJob($cluster->id))->handle(
            $this->app->make(LLMClient::class),
            $this->app->make(\App\Services\ScoringService::class),
        );

        $this->assertDatabaseHas('tag_proposals', [
            'slug'      => 'quantum-computing',
            'status'    => 'pending',
            'frequency' => 1,
        ]);
    }

    public function test_increments_frequency_for_repeated_proposal(): void
    {
        TagProposal::create([
            'slug'      => 'quantum-computing',
            'reason'    => 'First proposal',
            'frequency' => 1,
            'status'    => 'pending',
        ]);

        $this->bindFakeLLM([
            'canonical_title'   => 'Title',
            'canonical_summary' => 'Summary.',
            'tags'              => [],
            'tag_proposals'     => [
                ['slug' => 'quantum-computing', 'reason' => 'Same concept again'],
            ],
            'novelty_score'     => 0.5,
        ]);

        $cluster = $this->makeCluster();

        (new SynthesizeClusterJob($cluster->id))->handle(
            $this->app->make(LLMClient::class),
            $this->app->make(\App\Services\ScoringService::class),
        );

        $this->assertDatabaseHas('tag_proposals', [
            'slug'      => 'quantum-computing',
            'frequency' => 2,
        ]);
    }

    public function test_ignores_tag_proposals_matching_existing_taxonomy(): void
    {
        $this->bindFakeLLM([
            'canonical_title'   => 'Title',
            'canonical_summary' => 'Summary.',
            'tags'              => ['mcp'],
            'tag_proposals'     => [
                ['slug' => 'funding', 'reason' => 'Should be ignored, it is in taxonomy'],
            ],
            'novelty_score'     => 0.3,
        ]);

        $cluster = $this->makeCluster();

        (new SynthesizeClusterJob($cluster->id))->handle(
            $this->app->make(LLMClient::class),
            $this->app->make(\App\Services\ScoringService::class),
        );

        $this->assertDatabaseCount('tag_proposals', 0);
    }

    // --- helpers ---

    private function makeCluster(): Cluster
    {
        $report = Report::create([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'test',
            'payload'      => ['items' => []],
            'payload_hash' => str_repeat('f', 64),
            'ingested_at'  => now(),
        ]);

        $cluster = Cluster::create([
            'canonical_title' => 'Initial Title',
            'first_seen_at'   => now(),
            'last_seen_at'    => now(),
            'consensus_count' => 2,
            'status'          => 'active',
        ]);

        NewsItem::create([
            'report_id'             => $report->id,
            'cluster_id'            => $cluster->id,
            'section'               => 'strategic',
            'title'                 => 'Test news',
            'summary'               => 'Test summary',
            'entities'              => ['OpenAI'],
            'raw_tags'              => ['funding'],
            'importance_self_rated' => 4,
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
