<?php

declare(strict_types=1);

namespace App\Contracts;

interface EmbeddingDriver
{
    /** @return float[] */
    public function embed(string $text): array;
}
