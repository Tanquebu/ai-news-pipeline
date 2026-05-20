<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Cluster;
use App\Models\Report;
use Illuminate\Support\Facades\DB;

class DeleteReportAction
{
    public function execute(Report $report): void
    {
        DB::transaction(function () use ($report) {
            $clusterIds = $report->newsItems()
                ->whereNotNull('cluster_id')
                ->pluck('cluster_id')
                ->unique();

            // Cascade deletes news_items, sources, tag/entity pivots automatically.
            $report->delete();

            foreach ($clusterIds as $clusterId) {
                $cluster = Cluster::find($clusterId);
                if (!$cluster) {
                    continue;
                }

                $remaining = $cluster->newsItems()->count();

                if ($remaining === 0) {
                    $cluster->publications()->where('status', 'draft')->delete();
                    $cluster->delete();
                } else {
                    $cluster->update(['consensus_count' => $remaining]);
                }
            }
        });
    }
}
