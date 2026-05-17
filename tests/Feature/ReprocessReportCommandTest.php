<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EmbedNewsItemJob;
use App\Models\NewsItem;
use App\Models\Report;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReprocessReportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TagSeeder::class);
    }

    public function test_dispatches_embed_job_for_each_news_item(): void
    {
        Bus::fake();

        $report = $this->createReportWithItems(3);

        $this->artisan('reports:reprocess', ['report_id' => $report->id])
            ->assertExitCode(0);

        Bus::assertDispatchedTimes(EmbedNewsItemJob::class, 3);
    }

    public function test_returns_failure_for_unknown_report(): void
    {
        $this->artisan('reports:reprocess', ['report_id' => 99999])
            ->assertExitCode(1);
    }

    public function test_warns_and_succeeds_for_report_with_no_items(): void
    {
        Bus::fake();

        $report = Report::create([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'claude-opus-4-7',
            'payload'      => ['items' => []],
            'payload_hash' => str_repeat('c', 64),
            'ingested_at'  => now(),
        ]);

        $this->artisan('reports:reprocess', ['report_id' => $report->id])
            ->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    // --- helpers ---

    private function createReportWithItems(int $count): Report
    {
        $report = Report::create([
            'report_date'  => '2026-05-15',
            'source_ai'    => 'claude-opus-4-7',
            'payload'      => ['items' => []],
            'payload_hash' => str_repeat('d', 64),
            'ingested_at'  => now(),
        ]);

        for ($i = 0; $i < $count; $i++) {
            NewsItem::create([
                'report_id' => $report->id,
                'section'   => 'strategic',
                'title'     => "Item {$i}",
                'summary'   => 'Summary',
                'entities'  => [],
                'raw_tags'  => [],
            ]);
        }

        return $report;
    }
}
