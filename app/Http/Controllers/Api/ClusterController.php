<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GenerateArticleAction;
use App\Actions\GenerateLinkedInPostsAction;
use App\Http\Controllers\Controller;
use App\Models\Cluster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClusterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Cluster::with(['tags'])
            ->where('status', 'active')
            ->whereNotNull('total_score')
            ->orderByDesc('total_score');

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('slug', $request->query('tag')));
        }

        if ($request->filled('score_min')) {
            $query->where('total_score', '>=', (float) $request->query('score_min'));
        }

        if ($request->filled('since')) {
            $query->where('last_seen_at', '>=', $request->query('since'));
        }

        $clusters = $query->paginate(20);

        return response()->json($clusters);
    }

    public function show(Cluster $cluster): JsonResponse
    {
        $cluster->load([
            'tags',
            'newsItems.sources',
            'newsItems.tags',
            'newsItems.resolvedEntities',
        ]);

        $publications = $cluster->publications()->orderByDesc('generated_at')->get();

        return response()->json([
            'cluster'      => $cluster,
            'publications' => $publications,
        ]);
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
