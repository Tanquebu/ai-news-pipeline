<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EmbedNewsItemJob;
use App\Models\Report;
use Illuminate\Console\Command;

class ReprocessReportCommand extends Command
{
    protected $signature = 'reports:reprocess {report_id : ID of the report to reprocess}';

    protected $description = 'Re-dispatch embedding and clustering jobs for all items of a report';

    public function handle(): int
    {
        $reportId = (int) $this->argument('report_id');
        $report   = Report::find($reportId);

        if ($report === null) {
            $this->error("Report #{$reportId} not found.");

            return self::FAILURE;
        }

        $count = $report->newsItems()->count();

        if ($count === 0) {
            $this->warn("Report #{$reportId} has no news items.");

            return self::SUCCESS;
        }

        $report->newsItems()->each(function ($item) {
            EmbedNewsItemJob::dispatch($item->id);
        });

        $this->info("Dispatched {$count} EmbedNewsItemJob(s) for report #{$reportId}.");

        return self::SUCCESS;
    }
}
