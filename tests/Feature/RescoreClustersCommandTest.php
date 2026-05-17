<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\NewsItem;
use App\Models\Report;
use App\Models\Tag;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RescoreClustersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TagSeeder::class);
    }

    public function test_rescores_all_active_clusters(): void
    {
        $this->makeCluster('active', novelty: 0.6);
        $this->makeCluster('active', novelty: 0.4);

        $this->artisan('clusters:rescore')->assertExitCode(0);

        $scored = Cluster::whereNotNull('total_score')->count();
        $this->assertSame(2, $scored);
    }

    public function test_skips_archived_clusters(): void
    {
        $this->makeCluster('archived', novelty: 0.5);

        $this->artisan('clusters:rescore')->assertExitCode(0);

        $this->assertDatabaseMissing('clusters', ['total_score' => 0.0]);
        $this->assertNull(Cluster::first()->total_score);
    }

    public function test_warns_when_no_active_clusters(): void
    {
        $this->artisan('clusters:rescore')
            ->expectsOutputToContain('No active clusters')
            ->assertExitCode(0);
    }

    // --- helpers ---

    private function makeCluster(string $status, float $novelty): Cluster
    {
        $report = Report::firstOrCreate(
            ['payload_hash' => str_repeat('g', 64)],
            [
                'report_date' => '2026-05-15',
                'source_ai'   => 'test',
                'payload'     => ['items' => []],
                'ingested_at' => now(),
            ],
        );

        $cluster = Cluster::create([
            'canonical_title' => 'Cluster',
            'first_seen_at'   => now(),
            'last_seen_at'    => now(),
            'consensus_count' => 3,
            'novelty_score'   => $novelty,
            'status'          => $status,
        ]);

        NewsItem::create([
            'report_id'             => $report->id,
            'cluster_id'            => $cluster->id,
            'section'               => 'technical',
            'title'                 => 'Item',
            'summary'               => 'Summary',
            'entities'              => [],
            'raw_tags'              => [],
            'importance_self_rated' => 4,
        ]);

        return $cluster;
    }
}
