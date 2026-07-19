<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagProposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagProposalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pipeline.api_token' => 'test-token']);
    }

    private function auth(): array
    {
        return ['X-API-Token' => 'test-token'];
    }

    public function test_returns_401_without_token(): void
    {
        $this->getJson('/api/tag-proposals')->assertStatus(401);
    }

    public function test_lists_pending_proposals_ordered_by_frequency(): void
    {
        TagProposal::create(['slug' => 'a2a', 'reason' => 'r', 'frequency' => 4, 'status' => 'pending']);
        TagProposal::create(['slug' => 'security', 'reason' => 'r', 'frequency' => 12, 'status' => 'pending']);
        TagProposal::create(['slug' => 'ignored', 'reason' => 'r', 'frequency' => 99, 'status' => 'approved']);

        $response = $this->getJson('/api/tag-proposals', $this->auth())->assertOk();

        $this->assertSame(['security', 'a2a'], collect($response->json('data'))->pluck('slug')->all());
    }

    public function test_filters_by_query(): void
    {
        TagProposal::create(['slug' => 'agent-interoperability', 'reason' => 'r', 'frequency' => 6, 'status' => 'pending']);
        TagProposal::create(['slug' => 'security', 'reason' => 'r', 'frequency' => 12, 'status' => 'pending']);

        $this->getJson('/api/tag-proposals?q=agent', $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'agent-interoperability');
    }

    public function test_promote_creates_tag_and_marks_proposal_approved(): void
    {
        $proposal = TagProposal::create(['slug' => 'a2a', 'reason' => 'Protocollo agent-to-agent', 'frequency' => 4, 'status' => 'pending']);

        $response = $this->postJson("/api/tag-proposals/{$proposal->id}/promote", [], $this->auth())
            ->assertStatus(201)
            ->assertJsonPath('slug', 'a2a');

        $this->assertDatabaseHas('tags', ['slug' => 'a2a', 'description' => 'Protocollo agent-to-agent']);
        $this->assertSame('approved', $proposal->fresh()->status);
    }

    public function test_promote_returns_422_if_already_processed(): void
    {
        $proposal = TagProposal::create(['slug' => 'a2a', 'reason' => 'r', 'frequency' => 4, 'status' => 'approved']);

        $this->postJson("/api/tag-proposals/{$proposal->id}/promote", [], $this->auth())
            ->assertStatus(422);
    }

    public function test_promote_returns_422_if_tag_slug_already_exists(): void
    {
        Tag::create(['slug' => 'a2a', 'name' => 'A2A']);
        $proposal = TagProposal::create(['slug' => 'a2a', 'reason' => 'r', 'frequency' => 4, 'status' => 'pending']);

        $this->postJson("/api/tag-proposals/{$proposal->id}/promote", [], $this->auth())
            ->assertStatus(422);
    }
}
