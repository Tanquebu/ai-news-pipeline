<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Cluster;
use App\Services\ScoringService;

class RescoreClustersAction
{
    public function __construct(private readonly ScoringService $scoring)
    {
    }

    public function execute(): int
    {
        $count = 0;

        Cluster::where('status', 'active')
            ->with(['newsItems', 'tags'])
            ->cursor()
            ->each(function (Cluster $cluster) use (&$count) {
                $this->scoring->updateScore($cluster);
                $count++;
            });

        return $count;
    }
}
