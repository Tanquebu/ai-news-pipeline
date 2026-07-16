<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brief;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BriefController extends Controller
{
    /**
     * Lista dei brief settimanali, più recenti (e a score più alto) prima.
     * Filtro opzionale ?status=draft|approved|sent. Consumato dalla delivery
     * T3.4 (webhook n8n → Telegram, export verso il workspace).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Brief::query()
            // Select esplicita sul dossier: il centroide (vector 1536d) non
            // deve mai essere trasferito dal DB né finire nel payload.
            ->with('dossier:id,name,slug')
            ->orderByDesc('period_start')
            ->orderByRaw('score DESC NULLS LAST')
            ->orderBy('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $briefs = $query->get();

        return response()->json([
            'count'  => $briefs->count(),
            'briefs' => $briefs,
        ]);
    }

    public function show(Brief $brief): JsonResponse
    {
        $brief->load('dossier:id,name,slug,description,brief_score,is_brief_candidate,scored_at');

        return response()->json(['brief' => $brief]);
    }
}
