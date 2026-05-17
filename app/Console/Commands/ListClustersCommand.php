<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cluster;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ListClustersCommand extends Command
{
    protected $signature = 'clusters:list
                            {--top=10 : Number of clusters to show}
                            {--since= : Show clusters active since this date (e.g. yesterday, 2026-05-01)}';

    protected $description = 'List top clusters ordered by score';

    public function handle(): int
    {
        $top   = max(1, (int) $this->option('top'));
        $since = $this->option('since');

        $query = Cluster::with('tags')
            ->where('status', 'active')
            ->whereNotNull('total_score')
            ->orderByDesc('total_score')
            ->limit($top);

        if ($since !== null) {
            try {
                $sinceDate = Carbon::parse($since);
            } catch (\Exception) {
                $this->error("Cannot parse date: {$since}");

                return self::FAILURE;
            }

            $query->where('last_seen_at', '>=', $sinceDate);
        }

        $clusters = $query->get();

        if ($clusters->isEmpty()) {
            $this->warn('No scored clusters found.');

            return self::SUCCESS;
        }

        $rows = $clusters->map(fn (Cluster $c) => [
            $c->id,
            mb_strimwidth($c->canonical_title ?? $c->canonical_title, 0, 55, '…'),
            number_format((float) $c->total_score, 3),
            $c->consensus_count,
            $c->tags->pluck('slug')->join(', '),
            $c->last_seen_at?->format('Y-m-d H:i'),
        ]);

        $this->table(
            ['ID', 'Title', 'Score', 'Consensus', 'Tags', 'Last Seen'],
            $rows,
        );

        return self::SUCCESS;
    }
}
