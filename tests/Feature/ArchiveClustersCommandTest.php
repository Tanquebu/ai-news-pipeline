<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cluster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveClustersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_archives_clusters_older_than_threshold(): void
    {
        $old = $this->makeCluster(last_seen_at: now()->subDays(20));
        $fresh = $this->makeCluster(last_seen_at: now()->subDays(5));

        $this->artisan('clusters:archive', ['--older-than' => 14])
            ->assertExitCode(0);

        $this->assertSame('archived', $old->fresh()->status);
        $this->assertSame('active', $fresh->fresh()->status);
    }

    public function test_does_not_archive_when_no_old_clusters(): void
    {
        $this->makeCluster(last_seen_at: now()->subDays(3));

        $this->artisan('clusters:archive', ['--older-than' => 14])
            ->expectsOutputToContain('No clusters to archive')
            ->assertExitCode(0);
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $old = $this->makeCluster(last_seen_at: now()->subDays(20));

        $this->artisan('clusters:archive', ['--older-than' => 14, '--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);

        $this->assertSame('active', $old->fresh()->status);
    }

    public function test_uses_config_default_when_older_than_not_given(): void
    {
        config(['pipeline.cluster.archive_after_days' => 7]);

        $old = $this->makeCluster(last_seen_at: now()->subDays(10));

        $this->artisan('clusters:archive')->assertExitCode(0);

        $this->assertSame('archived', $old->fresh()->status);
    }

    // --- helpers ---

    private function makeCluster(\DateTimeInterface $last_seen_at): Cluster
    {
        return Cluster::create([
            'canonical_title' => 'Cluster ' . uniqid(),
            'first_seen_at'   => now()->subDays(30),
            'last_seen_at'    => $last_seen_at,
            'consensus_count' => 1,
            'total_score'     => 0.5,
            'status'          => 'active',
        ]);
    }
}
