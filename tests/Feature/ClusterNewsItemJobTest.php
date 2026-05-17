<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ClusterNewsItemJob;
use App\Jobs\SynthesizeClusterJob;
use App\Models\Cluster;
use App\Models\NewsItem;
use App\Models\Report;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClusterNewsItemJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SynthesizeClusterJob::class]);
        $this->seed(TagSeeder::class);
    }

    public function test_creates_new_cluster_when_no_candidates_exist(): void
    {
        $item = $this->createNewsItemWithEmbedding($this->vec(1, 0));

        (new ClusterNewsItemJob($item->id))->handle();

        $this->assertDatabaseCount('clusters', 1);
        $item->refresh();
        $this->assertNotNull($item->cluster_id);

        $cluster = Cluster::first();
        $this->assertSame($item->title, $cluster->canonical_title);
        $this->assertSame(1, $cluster->consensus_count);
    }

    public function test_assigns_to_existing_cluster_when_similarity_above_threshold(): void
    {
        // Identical vectors → similarity = 1.0
        $existing = $this->createNewsItemWithEmbedding($this->vec(1, 0));
        $cluster  = Cluster::create([
            'canonical_title' => 'Existing cluster',
            'first_seen_at'   => now()->subHour(),
            'last_seen_at'    => now()->subHour(),
            'consensus_count' => 1,
            'status'          => 'active',
        ]);
        $existing->update(['cluster_id' => $cluster->id]);

        $newItem = $this->createNewsItemWithEmbedding($this->vec(1, 0));

        (new ClusterNewsItemJob($newItem->id))->handle();

        $newItem->refresh();
        $this->assertSame($cluster->id, $newItem->cluster_id);

        $cluster->refresh();
        $this->assertSame(2, $cluster->consensus_count);
    }

    public function test_creates_new_cluster_when_similarity_below_threshold(): void
    {
        // Orthogonal vectors → similarity = 0.0
        $existing = $this->createNewsItemWithEmbedding($this->vec(1, 0));
        $cluster  = Cluster::create([
            'canonical_title' => 'Existing cluster',
            'first_seen_at'   => now()->subHour(),
            'last_seen_at'    => now()->subHour(),
            'consensus_count' => 1,
            'status'          => 'active',
        ]);
        $existing->update(['cluster_id' => $cluster->id]);

        $newItem = $this->createNewsItemWithEmbedding($this->vec(0, 1));

        (new ClusterNewsItemJob($newItem->id))->handle();

        $newItem->refresh();
        $this->assertNotEquals($cluster->id, $newItem->cluster_id);
        $this->assertDatabaseCount('clusters', 2);

        $cluster->refresh();
        $this->assertSame(1, $cluster->consensus_count);
    }

    public function test_creates_new_cluster_when_candidate_is_outside_time_window(): void
    {
        $windowHours = (int) config('pipeline.clustering.time_window_hours', 72);

        $old = $this->createNewsItemWithEmbedding($this->vec(1, 0));
        // Forza il created_at oltre la finestra
        DB::table('news_items')
            ->where('id', $old->id)
            ->update(['created_at' => now()->subHours($windowHours + 1)]);

        $cluster = Cluster::create([
            'canonical_title' => 'Old cluster',
            'first_seen_at'   => now()->subHours($windowHours + 1),
            'last_seen_at'    => now()->subHours($windowHours + 1),
            'consensus_count' => 1,
            'status'          => 'active',
        ]);
        $old->update(['cluster_id' => $cluster->id]);

        $newItem = $this->createNewsItemWithEmbedding($this->vec(1, 0));

        (new ClusterNewsItemJob($newItem->id))->handle();

        $newItem->refresh();
        $this->assertNotEquals($cluster->id, $newItem->cluster_id);
        $this->assertDatabaseCount('clusters', 2);
    }

    public function test_skips_item_already_assigned_to_cluster(): void
    {
        $cluster = Cluster::create([
            'canonical_title' => 'Existing cluster',
            'first_seen_at'   => now(),
            'last_seen_at'    => now(),
            'consensus_count' => 1,
            'status'          => 'active',
        ]);

        $item = $this->createNewsItemWithEmbedding($this->vec(1, 0));
        $item->update(['cluster_id' => $cluster->id]);

        (new ClusterNewsItemJob($item->id))->handle();

        $cluster->refresh();
        $this->assertSame(1, $cluster->consensus_count);
        $this->assertDatabaseCount('clusters', 1);
    }

    // --- helpers ---

    private function createNewsItemWithEmbedding(array $embedding): NewsItem
    {
        $report = Report::firstOrCreate(
            ['payload_hash' => str_repeat('b', 64)],
            [
                'report_date' => '2026-05-15',
                'source_ai'   => 'claude-opus-4-7',
                'payload'     => ['items' => []],
                'ingested_at' => now(),
            ],
        );

        $item = NewsItem::create([
            'report_id' => $report->id,
            'section'   => 'strategic',
            'title'     => 'Test news item ' . uniqid(),
            'summary'   => 'Test summary',
            'entities'  => [],
            'raw_tags'  => [],
        ]);

        DB::table('news_items')
            ->where('id', $item->id)
            ->update(['embedding' => '[' . implode(',', $embedding) . ']']);

        return $item;
    }

    /** @return float[] — unit vector in dimension $primary, 0 elsewhere */
    private function vec(float ...$values): array
    {
        $dims = (int) config('pipeline.embedding.dimensions', 1536);
        $vec  = array_fill(0, $dims, 0.0);

        foreach ($values as $i => $v) {
            $vec[$i] = $v;
        }

        return $vec;
    }
}
