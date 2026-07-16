<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brief;
use App\Models\Dossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BriefApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['pipeline.api_token' => $this->token]);
    }

    public function test_index_lists_briefs_with_dossier_and_payload(): void
    {
        $dossier = Dossier::factory()->create();

        $low  = Brief::factory()->create(['dossier_id' => $dossier->id, 'score' => 0.3]);
        $high = Brief::factory()->create(['score' => 0.9]);

        $response = $this->authed()->getJson('/api/briefs');

        $response->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('briefs.0.id', $high->id)
            ->assertJsonPath('briefs.1.id', $low->id)
            ->assertJsonPath('briefs.1.dossier.slug', $dossier->slug)
            ->assertJsonStructure([
                'count',
                'briefs' => [
                    ['id', 'dossier_id', 'period_start', 'title', 'score', 'payload', 'status', 'dossier' => ['id', 'name', 'slug']],
                ],
            ]);

        // Il centroide (vector 1536d) non deve mai uscire dal payload.
        $this->assertArrayNotHasKey('centroid', $response->json('briefs.0.dossier'));
    }

    public function test_index_filters_by_status(): void
    {
        Brief::factory()->create(['status' => Brief::STATUS_DRAFT]);
        $approved = Brief::factory()->create(['status' => Brief::STATUS_APPROVED]);

        $response = $this->authed()->getJson('/api/briefs?status=approved');

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('briefs.0.id', $approved->id)
            ->assertJsonPath('briefs.0.status', 'approved');
    }

    public function test_show_returns_full_brief(): void
    {
        $brief = Brief::factory()->create();

        $response = $this->authed()->getJson("/api/briefs/{$brief->id}");

        $response->assertOk()
            ->assertJsonPath('brief.id', $brief->id)
            ->assertJsonPath('brief.payload.thesis', $brief->payload['thesis'])
            ->assertJsonPath('brief.dossier.id', $brief->dossier_id)
            ->assertJsonStructure([
                'brief' => [
                    'id', 'dossier_id', 'period_start', 'title', 'score', 'status',
                    'payload' => ['theme', 'thesis', 'key_claims', 'counterarguments', 'risky_claims', 'suggested_format', 'editorial_angles', 'why_now', 'sources'],
                    'dossier' => ['id', 'name', 'slug'],
                ],
            ]);

        $this->assertArrayNotHasKey('centroid', $response->json('brief.dossier'));
    }

    public function test_show_returns_404_for_missing_brief(): void
    {
        $this->authed()->getJson('/api/briefs/999999')->assertNotFound();
    }

    public function test_endpoints_require_api_token(): void
    {
        $brief = Brief::factory()->create();

        $this->getJson('/api/briefs')->assertStatus(401);
        $this->getJson("/api/briefs/{$brief->id}")->assertStatus(401);
    }

    // --- helpers ---

    private function authed(): static
    {
        return $this->withHeaders(['X-API-Token' => $this->token]);
    }
}
