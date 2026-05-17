<?php

declare(strict_types=1);

namespace App\Services\Embedding;

use App\Contracts\EmbeddingDriver;
use Illuminate\Support\Facades\Http;

class VoyageEmbeddingDriver implements EmbeddingDriver
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function embed(string $text): array
    {
        $response = Http::withToken($this->apiKey)
            ->baseUrl(config('services.voyage.base_url'))
            ->post('embeddings', [
                'model' => $this->model,
                'input' => [$text],
            ])
            ->throw();

        return $response->json('data.0.embedding');
    }
}
