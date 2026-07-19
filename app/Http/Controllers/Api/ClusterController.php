<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GenerateArticleAction;
use App\Actions\GenerateLinkedInPostsAction;
use App\Actions\RescoreClustersAction;
use App\Http\Controllers\Controller;
use App\Models\Cluster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClusterController extends Controller
{
    public function rescoreAll(RescoreClustersAction $action): JsonResponse
    {
        return response()->json(['rescored' => $action->execute()]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Cluster::with(['tags', 'newsItems.report:id,source_ai'])
            ->withMin('newsItems', 'event_date')
            ->withMax('newsItems', 'event_date')
            ->where('status', 'active')
            ->whereNotNull('total_score')
            ->orderByDesc('total_score');

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('slug', $request->query('tag')));
        }

        if ($request->filled('score_min')) {
            $query->where('total_score', '>=', (float) $request->query('score_min'));
        }

        if ($request->filled('source_ai')) {
            $query->whereHas('newsItems.report', fn ($q) => $q->where('source_ai', $request->query('source_ai')));
        }

        if (! $request->boolean('show_all')) {
            $since = $request->filled('since')
                ? $request->query('since')
                : now()->subDays(config('pipeline.cluster.feed_window_days'))->toDateTimeString();

            // Priority: max event_date of news items (cast to timestamp); fallback to last_seen_at
            $query->whereRaw(
                'COALESCE((SELECT MAX(event_date)::timestamp FROM news_items WHERE cluster_id = clusters.id), clusters.last_seen_at) >= ?',
                [$since]
            );
        }

        $clusters = $query->paginate(20);

        return response()->json($clusters);
    }

    public function show(Cluster $cluster): JsonResponse
    {
        $cluster->load([
            'tags',
            'newsItems.report:id,source_ai',
            'newsItems.sources',
            'newsItems.tags',
            'newsItems.resolvedEntities',
        ]);

        $publications = $cluster->publications()->orderByDesc('generated_at')->get();

        $eventDates = $cluster->newsItems->pluck('event_date')->filter();
        $clusterData = array_merge($cluster->toArray(), [
            'news_items_min_event_date' => $eventDates->min()?->toDateString(),
            'news_items_max_event_date' => $eventDates->max()?->toDateString(),
        ]);

        return response()->json([
            'cluster'      => $clusterData,
            'publications' => $publications,
        ]);
    }

    public function archive(Cluster $cluster): JsonResponse
    {
        if ($cluster->status === 'archived') {
            return response()->json(['error' => 'Cluster is already archived.'], 422);
        }

        $cluster->update(['status' => 'archived']);

        return response()->json(['status' => 'archived']);
    }

    public function generateLinkedIn(Cluster $cluster, GenerateLinkedInPostsAction $action): JsonResponse
    {
        $publications = $action->execute($cluster);

        return response()->json($publications, 201);
    }

    public function generateArticle(Cluster $cluster, GenerateArticleAction $action): JsonResponse
    {
        try {
            $publication = $action->execute($cluster);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($publication, 201);
    }
}
