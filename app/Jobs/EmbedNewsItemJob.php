<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NewsItem;
use App\Services\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class EmbedNewsItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $newsItemId) {}

    public function handle(EmbeddingService $service): void
    {
        $item = NewsItem::findOrFail($this->newsItemId);

        $embedding = $service->embedNewsItem($item);

        DB::table('news_items')
            ->where('id', $item->id)
            ->update(['embedding' => '[' . implode(',', $embedding) . ']']);

        ClusterNewsItemJob::dispatch($item->id);
    }
}
