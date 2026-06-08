<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cluster;
use Illuminate\Console\Command;

class ArchiveClustersCommand extends Command
{
    protected $signature = 'clusters:archive
        {--older-than= : Archive clusters whose last_seen_at is older than this many days (default from config)}
        {--dry-run : Show what would be archived without making changes}';

    protected $description = 'Archive active clusters that have not been updated recently';

    public function handle(): int
    {
        $days = (int) ($this->option('older-than') ?? config('pipeline.cluster.archive_after_days'));
        $cutoff = now()->subDays($days);
        $dryRun = $this->option('dry-run');

        $query = Cluster::where('status', 'active')
            ->where('last_seen_at', '<', $cutoff);

        $count = $query->count();

        if ($count === 0) {
            $this->info('No clusters to archive.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] Would archive {$count} cluster(s) not seen since {$cutoff->toDateString()}.");

            return self::SUCCESS;
        }

        $query->update(['status' => 'archived']);

        $this->info("Archived {$count} cluster(s) not seen since {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
