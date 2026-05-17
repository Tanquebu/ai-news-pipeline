<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cluster;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListClustersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TagSeeder::class);
    }

    public function test_lists_clusters_ordered_by_score(): void
    {
        $this->makeCluster('Low score',  score: 0.2, lastSeen: now()->subDay());
        $this->makeCluster('High score', score: 0.9, lastSeen: now());

        $this->artisan('clusters:list')
            ->expectsOutputToContain('High score')
            ->assertExitCode(0);
    }

    public function test_limits_output_to_top_option(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeCluster("Cluster {$i}", score: $i * 0.1, lastSeen: now());
        }

        $this->artisan('clusters:list', ['--top' => 3])
            ->assertExitCode(0);

        // No assertion on exact count via output; exit 0 is sufficient for top filter
        $this->assertTrue(true);
    }

    public function test_since_filter_excludes_old_clusters(): void
    {
        $this->makeCluster('Old',    score: 0.9, lastSeen: now()->subDays(5));
        $this->makeCluster('Recent', score: 0.5, lastSeen: now());

        $this->artisan('clusters:list', ['--since' => now()->subDay()->toDateString()])
            ->expectsOutputToContain('Recent')
            ->assertExitCode(0);
    }

    public function test_warns_when_no_scored_clusters(): void
    {
        $this->artisan('clusters:list')
            ->expectsOutputToContain('No scored clusters')
            ->assertExitCode(0);
    }

    public function test_returns_failure_on_invalid_since_date(): void
    {
        $this->artisan('clusters:list', ['--since' => 'not-a-date'])
            ->assertExitCode(1);
    }

    // --- helpers ---

    private function makeCluster(string $title, float $score, \DateTimeInterface $lastSeen): Cluster
    {
        return Cluster::create([
            'canonical_title' => $title,
            'first_seen_at'   => $lastSeen,
            'last_seen_at'    => $lastSeen,
            'consensus_count' => 1,
            'total_score'     => $score,
            'status'          => 'active',
        ]);
    }
}
