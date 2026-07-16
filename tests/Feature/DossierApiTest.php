<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DossierApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['pipeline.api_token' => $this->token]);
    }

    public function test_index_returns_dossiers_ordered_by_score_with_breakdown(): void
    {
        $low = Dossier::factory()->create([
            'brief_score'        => 0.21,
            'is_brief_candidate' => false,
            'score_breakdown'    => ['score' => 0.21, 'components' => []],
            'scored_at'          => now(),
            'document_count'     => 2,
        ]);
        $high = Dossier::factory()->create([
            'brief_score'        => 0.84,
            'is_brief_candidate' => true,
            'score_breakdown'    => ['score' => 0.84, 'components' => []],
            'scored_at'          => now(),
            'document_count'     => 9,
        ]);
        $unscored = Dossier::factory()->create();

        $response = $this->authed()->getJson('/api/dossiers');

        $response->assertOk()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('dossiers.0.id', $high->id)
            ->assertJsonPath('dossiers.0.brief_score', 0.84)
            ->assertJsonPath('dossiers.0.is_brief_candidate', true)
            ->assertJsonPath('dossiers.0.document_count', 9)
            ->assertJsonPath('dossiers.1.id', $low->id)
            ->assertJsonPath('dossiers.2.id', $unscored->id)
            ->assertJsonPath('dossiers.2.brief_score', null)
            ->assertJsonStructure([
                'count',
                'dossiers' => [
                    ['id', 'name', 'slug', 'description', 'document_count', 'brief_score', 'score_breakdown', 'is_brief_candidate', 'scored_at'],
                ],
            ]);

        // Il centroide (vector 1536d) non deve mai uscire dal payload.
        $this->assertArrayNotHasKey('centroid', $response->json('dossiers.0'));

        $this->assertSame(0.84, $response->json('dossiers.0.score_breakdown.score'));
    }

    public function test_index_filters_candidates_only(): void
    {
        $candidate = Dossier::factory()->create([
            'brief_score'        => 0.84,
            'is_brief_candidate' => true,
            'scored_at'          => now(),
        ]);
        Dossier::factory()->create([
            'brief_score'        => 0.21,
            'is_brief_candidate' => false,
            'scored_at'          => now(),
        ]);

        $response = $this->authed()->getJson('/api/dossiers?candidates_only=1');

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('dossiers.0.id', $candidate->id);
    }

    public function test_index_requires_api_token(): void
    {
        $this->getJson('/api/dossiers')->assertStatus(401);
    }

    // --- helpers ---

    private function authed(): static
    {
        return $this->withHeaders(['X-API-Token' => $this->token]);
    }
}
