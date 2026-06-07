<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AnthropicService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicServiceTest extends TestCase
{
    private AnthropicService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-key']);
        config(['services.anthropic.model' => 'claude-test-model']);
        $this->service = new AnthropicService();
    }

    public function test_complete_returns_text_from_successful_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Hello from Claude'],
                ],
            ], 200),
        ]);

        $result = $this->service->complete('Say hello');

        $this->assertSame('Hello from Claude', $result);
    }

    public function test_complete_retries_on_500_and_eventually_throws(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response('Internal Server Error', 500),
        ]);

        $this->expectException(RequestException::class);

        $this->service->complete('test prompt');

        Http::assertSentCount(3);
    }

    public function test_complete_does_not_retry_on_429(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response('Too Many Requests', 429),
        ]);

        $this->expectException(RequestException::class);

        try {
            $this->service->complete('test prompt');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_complete_does_not_retry_on_529(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response('Overloaded', 529),
        ]);

        $this->expectException(RequestException::class);

        try {
            $this->service->complete('test prompt');
        } finally {
            Http::assertSentCount(1);
        }
    }
}
