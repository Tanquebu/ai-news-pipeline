<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\GenerateLinkedInPostsAction;
use App\Contracts\LLMClient;
use App\Models\Cluster;
use App\Models\NewsItem;
use App\Models\Report;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateLinkedInPostsActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TagSeeder::class);
    }

    public function test_creates_four_publication_drafts(): void
    {
        $this->bindFakeLLM([
            'short'   => 'Post corto',
            'medium'  => 'Post medio con più contesto',
            'opinion' => 'Post opinione editoriale',
            'large'   => 'Post lungo e strutturato con hook, sviluppo e call-to-action.',
        ]);

        $cluster = $this->makeCluster();

        $action        = $this->app->make(GenerateLinkedInPostsAction::class);
        $publications  = $action->execute($cluster);

        $this->assertCount(4, $publications);
        $this->assertDatabaseCount('publications', 4);

        $kinds = collect($publications)->pluck('kind')->sort()->values()->all();
        $this->assertSame(['linkedin_large', 'linkedin_medium', 'linkedin_opinion', 'linkedin_short'], $kinds);

        foreach ($publications as $pub) {
            $this->assertSame('draft', $pub->status);
            $this->assertSame($cluster->id, $pub->cluster_id);
            $this->assertNotEmpty($pub->body);
        }
    }

    // --- helpers ---

    private function makeCluster(): Cluster
    {
        $cluster = Cluster::create([
            'canonical_title'   => 'Test Cluster',
            'canonical_summary' => 'Test summary',
            'first_seen_at'     => now(),
            'last_seen_at'      => now(),
            'consensus_count'   => 1,
            'status'            => 'active',
        ]);

        return $cluster;
    }

    private function bindFakeLLM(array $response): void
    {
        $json = json_encode($response);

        $this->app->bind(LLMClient::class, fn () => new class ($json) implements LLMClient {
            public function __construct(private readonly string $json) {}

            public function complete(string $prompt, int $maxTokens = 1024): string
            {
                return $this->json;
            }
        });
    }
}
