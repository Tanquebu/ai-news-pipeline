<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EmbeddingDriver;
use App\Models\NewsItem;

class EmbeddingService
{
    public function __construct(private readonly EmbeddingDriver $driver) {}

    /** @return float[] */
    public function embedNewsItem(NewsItem $item): array
    {
        return $this->driver->embed($item->title . "\n" . $item->summary);
    }

    /** @return float[] */
    public function embedText(string $text): array
    {
        return $this->driver->embed($text);
    }
}
