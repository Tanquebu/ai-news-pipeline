<?php

declare(strict_types=1);

namespace App\Services\Embedding;

use App\Contracts\EmbeddingDriver;
use Illuminate\Support\Facades\Http;

class OpenAIEmbeddingDriver implements EmbeddingDriver
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $dimensions,
    ) {}

    public function embed(string $text): array
    {
        $response = Http::withToken($this->apiKey)
            ->baseUrl(config('services.openai.base_url'))
            ->post('embeddings', [
                'model'      => $this->model,
                'input'      => $text,
                'dimensions' => $this->dimensions,
            ])
            ->throw();

        return $response->json('data.0.embedding');
    }
}
