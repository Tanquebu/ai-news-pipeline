<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RescoreClustersAction;
use App\Models\Cluster;
use Illuminate\Console\Command;

class RescoreClustersCommand extends Command
{
    protected $signature = 'clusters:rescore';

    protected $description = 'Recalculate total_score for all active clusters';

    public function handle(RescoreClustersAction $action): int
    {
        $total = Cluster::where('status', 'active')->count();

        if ($total === 0) {
            $this->warn('No active clusters found.');

            return self::SUCCESS;
        }

        $this->info("Rescoring {$total} active cluster(s)...");
        $count = $action->execute();
        $this->info("Done. Rescored {$count} cluster(s).");

        return self::SUCCESS;
    }
}
