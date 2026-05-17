<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = NewsItem::with(['cluster:id,canonical_title,total_score', 'tags:id,name,slug'])
            ->orderByDesc('created_at')
            ->limit(50);

        if ($search = $request->query('query')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                    ->orWhere('summary', 'ilike', "%{$search}%");
            });
        }

        if ($since = $request->query('since')) {
            $query->where('created_at', '>=', $since);
        }

        if ($section = $request->query('section')) {
            $query->where('section', $section);
        }

        return response()->json(['data' => $query->get()]);
    }
}
