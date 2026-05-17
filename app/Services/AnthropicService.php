<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LLMClient;
use Illuminate\Support\Facades\Http;

class AnthropicService implements LLMClient
{
    public function complete(string $prompt, int $maxTokens = 1024): string
    {
        $response = Http::withHeaders([
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
            ])
            ->timeout(60)
            ->retry(3, fn (int $attempt) => 1000 * (2 ** ($attempt - 1)))
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => config('services.anthropic.model', 'claude-opus-4-7'),
                'max_tokens' => $maxTokens,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ])
            ->throw();

        return $response->json('content.0.text');
    }
}
