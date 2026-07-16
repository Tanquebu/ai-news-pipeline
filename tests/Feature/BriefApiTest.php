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
        $this->patchJson("/api/briefs/{$brief->id}", ['status' => 'approved'])->assertStatus(401);
    }

    public function test_patch_advances_draft_to_approved_and_approved_to_sent(): void
    {
        $brief = Brief::factory()->create(['status' => Brief::STATUS_DRAFT]);

        $this->authed()
            ->patchJson("/api/briefs/{$brief->id}", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('brief.id', $brief->id)
            ->assertJsonPath('brief.status', 'approved');

        $this->assertDatabaseHas('briefs', ['id' => $brief->id, 'status' => 'approved']);

        $this->authed()
            ->patchJson("/api/briefs/{$brief->id}", ['status' => 'sent'])
            ->assertOk()
            ->assertJsonPath('brief.status', 'sent');

        $this->assertDatabaseHas('briefs', ['id' => $brief->id, 'status' => 'sent']);
    }

    public function test_patch_rejects_invalid_transitions(): void
    {
        $draft = Brief::factory()->create(['status' => Brief::STATUS_DRAFT]);
        $sent  = Brief::factory()->create(['status' => Brief::STATUS_SENT]);

        // Salto draft → sent: l'approvazione è una decisione umana esplicita.
        $this->authed()
            ->patchJson("/api/briefs/{$draft->id}", ['status' => 'sent'])
            ->assertStatus(422);

        // sent è terminale: nessuna transizione ulteriore via API.
        $this->authed()
            ->patchJson("/api/briefs/{$sent->id}", ['status' => 'approved'])
            ->assertStatus(422);

        $this->assertDatabaseHas('briefs', ['id' => $draft->id, 'status' => 'draft']);
        $this->assertDatabaseHas('briefs', ['id' => $sent->id, 'status' => 'sent']);
    }

    public function test_patch_rejects_unknown_or_missing_status(): void
    {
        $brief = Brief::factory()->create(['status' => Brief::STATUS_DRAFT]);

        // draft non è un target valido (nessun rollback via API).
        $this->authed()->patchJson("/api/briefs/{$brief->id}", ['status' => 'draft'])->assertStatus(422);
        $this->authed()->patchJson("/api/briefs/{$brief->id}", ['status' => 'published'])->assertStatus(422);
        $this->authed()->patchJson("/api/briefs/{$brief->id}", [])->assertStatus(422);

        $this->assertDatabaseHas('briefs', ['id' => $brief->id, 'status' => 'draft']);
    }

    // --- helpers ---

    private function authed(): static
    {
        return $this->withHeaders(['X-API-Token' => $this->token]);
    }
}
