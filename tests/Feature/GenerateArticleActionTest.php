<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\GenerateArticleAction;
use App\Contracts\LLMClient;
use App\Models\Cluster;
use App\Models\NewsItem;
use App\Models\Report;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateArticleActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TagSeeder::class);
    }

    public function test_creates_article_draft_for_eligible_cluster(): void
    {
        $this->bindFakeLLM([
            'title' => 'Articolo generato',
            'body'  => "# Articolo\n\nCorpo dell'articolo.",
        ]);

        $cluster = $this->makeCluster(items: 3, entities: ['OpenAI', 'Google', 'Microsoft']);

        $action      = $this->app->make(GenerateArticleAction::class);
        $publication = $action->execute($cluster);

        $this->assertDatabaseCount('publications', 1);
        $this->assertSame('article', $publication->kind);
        $this->assertSame('draft', $publication->status);
        $this->assertSame('Articolo generato', $publication->title);
    }

    public function test_throws_for_cluster_with_too_few_items(): void
    {
        $cluster = $this->makeCluster(items: 2, entities: ['OpenAI', 'Google']);

        $action = $this->app->make(GenerateArticleAction::class);

        $this->expectException(\RuntimeException::class);
        $action->execute($cluster);
    }

    public function test_throws_for_cluster_with_too_few_entities(): void
    {
        $cluster = $this->makeCluster(items: 3, entities: ['OpenAI']);

        $action = $this->app->make(GenerateArticleAction::class);

        $this->expectException(\RuntimeException::class);
        $action->execute($cluster);
    }

    // --- helpers ---

    private function makeCluster(int $items, array $entities): Cluster
    {
        $report = Report::create([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'test',
            'payload'      => ['items' => []],
            'payload_hash' => str_repeat('h', 64),
            'ingested_at'  => now(),
        ]);

        $cluster = Cluster::create([
            'canonical_title'   => 'Test',
            'canonical_summary' => 'Summary',
            'first_seen_at'     => now(),
            'last_seen_at'      => now(),
            'consensus_count'   => $items,
            'status'            => 'active',
        ]);

        for ($i = 0; $i < $items; $i++) {
            NewsItem::create([
                'report_id'  => $report->id,
                'cluster_id' => $cluster->id,
                'section'    => 'strategic',
                'title'      => "Item {$i}",
                'summary'    => 'Summary',
                'entities'   => $entities,
                'raw_tags'   => [],
            ]);
        }

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
