<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TagProposal;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoringInfoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TagSeeder::class);

        config(['pipeline.api_token' => 'test-token']);
    }

    private function auth(): array
    {
        return ['X-API-Token' => 'test-token'];
    }

    public function test_returns_401_without_token(): void
    {
        $this->getJson('/api/scoring/info')->assertStatus(401);
    }

    public function test_returns_weights_and_tags(): void
    {
        TagProposal::create(['slug' => 'a2a', 'reason' => 'r', 'frequency' => 4, 'status' => 'pending']);
        TagProposal::create(['slug' => 'old', 'reason' => 'r', 'frequency' => 1, 'status' => 'approved']);

        config([
            'pipeline.scoring.weight_consensus'   => 0.15,
            'pipeline.scoring.weight_novelty'     => 0.20,
            'pipeline.scoring.weight_importance'  => 0.35,
            'pipeline.scoring.weight_topic_match' => 0.30,
            'pipeline.scoring.consensus_saturation' => 3,
            'pipeline.scoring.topic_interest_tags'  => ['mcp', 'enterprise-adoption'],
        ]);

        $response = $this->getJson('/api/scoring/info', $this->auth())->assertOk();

        $response->assertJsonPath('weights.consensus', 0.15)
            ->assertJsonPath('weights.importance', 0.35)
            ->assertJsonPath('consensus_saturation', 3)
            ->assertJsonPath('topic_interest_tags', ['mcp', 'enterprise-adoption'])
            ->assertJsonPath('tag_proposals_count', 1);

        $this->assertNotEmpty($response->json('tags'));
        $this->assertArrayHasKey('slug', $response->json('tags.0'));
    }
}
