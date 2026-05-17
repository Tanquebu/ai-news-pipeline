<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cluster;

class ScoringService
{
    public function updateScore(Cluster $cluster): void
    {
        $cluster->loadMissing(['newsItems', 'tags']);

        $items = $cluster->newsItems;

        $importanceAvg = $items->isEmpty()
            ? 3.0
            : (float) $items->avg(fn ($item) => $item->importance_self_rated ?? 3);

        $interestSlugs = config('pipeline.scoring.topic_interest_tags', []);
        $clusterSlugs  = $cluster->tags->pluck('slug')->all();

        $topicMatch = empty($clusterSlugs)
            ? 0.0
            : count(array_intersect($clusterSlugs, $interestSlugs)) / count($clusterSlugs);

        $w1 = (float) config('pipeline.scoring.weight_consensus', 0.35);
        $w2 = (float) config('pipeline.scoring.weight_novelty', 0.20);
        $w3 = (float) config('pipeline.scoring.weight_importance', 0.20);
        $w4 = (float) config('pipeline.scoring.weight_topic_match', 0.25);

        $consensus      = min($cluster->consensus_count / 10.0, 1.0);
        $novelty        = (float) ($cluster->novelty_score ?? 0.0);
        $importanceNorm = ($importanceAvg - 1.0) / 4.0;

        $totalScore = $w1 * $consensus + $w2 * $novelty + $w3 * $importanceNorm + $w4 * $topicMatch;

        $cluster->update([
            'importance_avg'    => $importanceAvg,
            'topic_match_score' => $topicMatch,
            'total_score'       => round($totalScore, 4),
        ]);
    }
}
