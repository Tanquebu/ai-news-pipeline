<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\DeleteReportAction;
use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    public function index(): JsonResponse
    {
        $reports = Report::withCount('newsItems')
            ->orderByDesc('report_date')
            ->orderByDesc('ingested_at')
            ->paginate(50);

        return response()->json($reports);
    }

    public function destroy(Report $report, DeleteReportAction $action): Response
    {
        $action->execute($report);

        return response()->noContent();
    }
}
