<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cluster;
use App\Services\ScoringService;
use Illuminate\Console\Command;

class RescoreClustersCommand extends Command
{
    protected $signature = 'clusters:rescore';

    protected $description = 'Recalculate total_score for all active clusters';

    public function handle(ScoringService $scoring): int
    {
        $total = Cluster::where('status', 'active')->count();

        if ($total === 0) {
            $this->warn('No active clusters found.');

            return self::SUCCESS;
        }

        $this->info("Rescoring {$total} active cluster(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Cluster::where('status', 'active')
            ->with(['newsItems', 'tags'])
            ->cursor()
            ->each(function (Cluster $cluster) use ($scoring, $bar) {
                $scoring->updateScore($cluster);
                $bar->advance();
            });

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
