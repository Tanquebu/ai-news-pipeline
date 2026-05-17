<?php

declare(strict_types=1);

namespace App\Contracts;

interface LLMClient
{
    public function complete(string $prompt, int $maxTokens = 1024): string;
}
