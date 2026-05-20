<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cluster;
use App\Models\NewsItem;
use App\Models\Publication;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pipeline.api_token' => $this->token]);
    }

    // --- helpers ---

    private function authed(): static
    {
        return $this->withHeaders(['X-API-Token' => $this->token]);
    }

    private function makeReport(array $overrides = []): Report
    {
        static $seq = 0;
        $seq++;

        return Report::create(array_merge([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'claude-opus-4-7',
            'payload'      => [],
            'payload_hash' => "hash-{$seq}",
            'ingested_at'  => now(),
        ], $overrides));
    }

    private function makeNewsItem(Report $report, array $overrides = []): NewsItem
    {
        return NewsItem::create(array_merge([
            'report_id' => $report->id,
            'section'   => 'strategic',
            'title'     => 'Test news item',
            'summary'   => 'Summary.',
            'entities'  => [],
            'raw_tags'  => [],
        ], $overrides));
    }

    private function makeCluster(array $overrides = []): Cluster
    {
        return Cluster::create(array_merge([
            'canonical_title' => 'Test cluster',
            'first_seen_at'   => now(),
            'last_seen_at'    => now(),
            'consensus_count' => 1,
            'status'          => 'active',
        ], $overrides));
    }

    // --- tests ---

    public function test_index_returns_paginated_reports_with_item_counts(): void
    {
        $r1 = $this->makeReport(['report_date' => '2026-05-15']);
        $this->makeNewsItem($r1);
        $this->makeNewsItem($r1);

        $r2 = $this->makeReport(['report_date' => '2026-05-14', 'source_ai' => 'gpt-5']);

        $response = $this->authed()->getJson('/api/reports');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $r1->id)
            ->assertJsonPath('data.0.news_items_count', 2)
            ->assertJsonPath('data.1.id', $r2->id)
            ->assertJsonPath('data.1.news_items_count', 0);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $report = $this->makeReport();

        $this->getJson('/api/reports')->assertUnauthorized();
        $this->deleteJson("/api/reports/{$report->id}")->assertUnauthorized();
    }

    public function test_destroy_deletes_report_and_cascades_to_news_items(): void
    {
        $report = $this->makeReport();
        $item   = $this->makeNewsItem($report);

        $this->authed()->deleteJson("/api/reports/{$report->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('reports', ['id' => $report->id]);
        $this->assertDatabaseMissing('news_items', ['id' => $item->id]);
    }

    public function test_destroy_removes_cluster_when_no_items_remain(): void
    {
        $report  = $this->makeReport();
        $cluster = $this->makeCluster();
        $this->makeNewsItem($report, ['cluster_id' => $cluster->id]);

        $this->authed()->deleteJson("/api/reports/{$report->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('clusters', ['id' => $cluster->id]);
    }

    public function test_destroy_updates_consensus_count_when_cluster_has_remaining_items(): void
    {
        $report1 = $this->makeReport();
        $report2 = $this->makeReport(['source_ai' => 'gpt-5']);
        $cluster = $this->makeCluster(['consensus_count' => 2]);

        $this->makeNewsItem($report1, ['cluster_id' => $cluster->id]);
        $this->makeNewsItem($report2, ['cluster_id' => $cluster->id]);

        $this->authed()->deleteJson("/api/reports/{$report1->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('clusters', [
            'id'              => $cluster->id,
            'consensus_count' => 1,
        ]);
    }

    public function test_destroy_removes_draft_publications_of_empty_cluster(): void
    {
        $report  = $this->makeReport();
        $cluster = $this->makeCluster();
        $this->makeNewsItem($report, ['cluster_id' => $cluster->id]);

        $pub = Publication::create([
            'cluster_id'   => $cluster->id,
            'kind'         => 'linkedin_short',
            'status'       => 'draft',
            'title'        => 'Draft post',
            'body'         => 'Body.',
            'generated_at' => now(),
        ]);

        $this->authed()->deleteJson("/api/reports/{$report->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('publications', ['id' => $pub->id]);
    }

    public function test_destroy_preserves_approved_publications_of_empty_cluster(): void
    {
        $report  = $this->makeReport();
        $cluster = $this->makeCluster();
        $this->makeNewsItem($report, ['cluster_id' => $cluster->id]);

        $pub = Publication::create([
            'cluster_id'   => $cluster->id,
            'kind'         => 'linkedin_short',
            'status'       => 'approved',
            'title'        => 'Approved post',
            'body'         => 'Body.',
            'generated_at' => now(),
        ]);

        $this->authed()->deleteJson("/api/reports/{$report->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('publications', ['id' => $pub->id]);
    }

    public function test_destroy_returns_404_for_nonexistent_report(): void
    {
        $this->authed()->deleteJson('/api/reports/99999')
            ->assertNotFound();
    }
}
