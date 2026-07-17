<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\DeleteReportAction;
use App\Actions\IngestReportAction;
use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

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

    public function show(Report $report): JsonResponse
    {
        $report->load(['newsItems.sources', 'newsItems.tags']);

        return response()->json($report);
    }

    public function generators(): JsonResponse
    {
        $generators = Report::select('source_ai')
            ->distinct()
            ->orderBy('source_ai')
            ->pluck('source_ai');

        return response()->json($generators);
    }

    public function ingest(Request $request, IngestReportAction $action): JsonResponse
    {
        try {
            $data = $request->validate([
                'source_ai'   => ['required', 'string'],
                'report_date' => ['required', 'date_format:Y-m-d'],
                'items'       => ['required', 'array'],
            ]);

            $ingested = $action->execute($data);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->implode(' ');

            return response()->json(['error' => $message], 422);
        }

        return $ingested
            ? response()->json(['status' => 'ingested'], 201)
            : response()->json(['status' => 'duplicate'], 200);
    }

    public function destroy(Report $report, DeleteReportAction $action): Response
    {
        $action->execute($report);

        return response()->noContent();
    }
}
