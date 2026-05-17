<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\EmbeddingDriver;
use App\Contracts\LLMClient;
use App\Services\AnthropicService;
use App\Services\Embedding\OpenAIEmbeddingDriver;
use App\Services\Embedding\VoyageEmbeddingDriver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmbeddingDriver::class, function () {
            $driver     = config('pipeline.embedding.driver');
            $model      = config('pipeline.embedding.model');
            $dimensions = config('pipeline.embedding.dimensions');

            return match ($driver) {
                'voyage' => new VoyageEmbeddingDriver(
                    apiKey: config('services.voyage.api_key'),
                    model: $model,
                ),
                default => new OpenAIEmbeddingDriver(
                    apiKey: config('services.openai.api_key'),
                    model: $model,
                    dimensions: $dimensions,
                ),
            };
        });

        $this->app->bind(LLMClient::class, AnthropicService::class);
    }

    public function boot(): void {}
}
