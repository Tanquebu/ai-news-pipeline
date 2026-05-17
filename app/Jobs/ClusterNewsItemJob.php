<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Cluster;
use App\Models\NewsItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ClusterNewsItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $newsItemId) {}

    public function handle(): void
    {
        $item = NewsItem::findOrFail($this->newsItemId);

        if ($item->cluster_id !== null) {
            return;
        }

        $embeddingRaw = DB::scalar('SELECT embedding::text FROM news_items WHERE id = ?', [$item->id]);

        if ($embeddingRaw === null) {
            return;
        }

        $threshold   = (float) config('pipeline.clustering.similarity_threshold', 0.85);
        $windowHours = (int) config('pipeline.clustering.time_window_hours', 72);

        $match = DB::selectOne(
            "SELECT cluster_id, 1 - (embedding <=> ?) AS similarity
             FROM news_items
             WHERE cluster_id IS NOT NULL
               AND embedding IS NOT NULL
               AND id != ?
               AND created_at >= NOW() - (? * INTERVAL '1 hour')
             ORDER BY embedding <=> ? ASC
             LIMIT 1",
            [$embeddingRaw, $item->id, $windowHours, $embeddingRaw],
        );

        $now = now();

        if ($match !== null && $match->similarity >= $threshold) {
            $item->update(['cluster_id' => $match->cluster_id]);

            Cluster::where('id', $match->cluster_id)->update([
                'consensus_count' => DB::raw('consensus_count + 1'),
                'last_seen_at'    => $now,
            ]);

            $clusterId = $match->cluster_id;
        } else {
            $cluster = Cluster::create([
                'canonical_title' => $item->title,
                'first_seen_at'   => $now,
                'last_seen_at'    => $now,
                'consensus_count' => 1,
                'status'          => 'active',
            ]);

            $item->update(['cluster_id' => $cluster->id]);

            $clusterId = $cluster->id;
        }

        SynthesizeClusterJob::dispatch($clusterId);
    }
}
