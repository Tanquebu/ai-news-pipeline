<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\Publication;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicationApiTest extends TestCase
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
        $this->getJson('/api/publications')->assertStatus(401);
    }

    public function test_lists_publications(): void
    {
        $this->makePublication('draft');
        $this->makePublication('approved');

        $response = $this->getJson('/api/publications', $this->auth())->assertOk();
        $statuses = collect($response->json('data'))->pluck('status')->sort()->values()->all();
        $this->assertSame(['approved', 'draft'], $statuses);
    }

    public function test_filters_by_status(): void
    {
        $this->makePublication('draft');
        $this->makePublication('approved');

        $this->getJson('/api/publications?status=draft', $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_approve_publication(): void
    {
        $pub = $this->makePublication('draft');

        $this->patchJson("/api/publications/{$pub->id}", ['status' => 'approved'], $this->auth())
            ->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    public function test_publish_sets_published_at(): void
    {
        $pub = $this->makePublication('approved');

        $response = $this->patchJson(
            "/api/publications/{$pub->id}",
            ['status' => 'published'],
            $this->auth()
        )->assertOk();

        $this->assertNotNull($response->json('published_at'));
    }

    public function test_edit_body(): void
    {
        $pub = $this->makePublication('draft');

        $this->patchJson(
            "/api/publications/{$pub->id}",
            ['body' => 'Nuovo corpo del post'],
            $this->auth()
        )
            ->assertOk()
            ->assertJsonPath('body', 'Nuovo corpo del post');
    }

    public function test_export_returns_markdown(): void
    {
        $pub = $this->makePublication('approved', kind: 'article');

        $this->get("/api/publications/{$pub->id}/export", $this->auth())
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
    }

    // --- helpers ---

    private function makePublication(string $status, string $kind = 'linkedin_short'): Publication
    {
        $cluster = Cluster::create([
            'canonical_title' => 'Test',
            'first_seen_at'   => now(),
            'last_seen_at'    => now(),
            'consensus_count' => 1,
            'status'          => 'active',
        ]);

        return Publication::create([
            'cluster_id'   => $cluster->id,
            'kind'         => $kind,
            'status'       => $status,
            'title'        => 'Test Publication',
            'body'         => '# Test',
            'generated_at' => now(),
        ]);
    }
}
