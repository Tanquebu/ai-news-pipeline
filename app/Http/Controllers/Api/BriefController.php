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

    /**
     * Avanzamento di stato del brief lungo il ciclo editoriale (T3.4):
     * draft → approved (decisione umana dal digest Telegram) e
     * approved → sent (export verso il workspace avvenuto). Solo transizioni
     * in avanti di un passo: niente salti draft→sent, niente rollback — se
     * serve tornare indietro si interviene a mano sul DB, non via API.
     */
    public function update(Request $request, Brief $brief): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . Brief::STATUS_APPROVED . ',' . Brief::STATUS_SENT],
        ]);

        $allowed = [
            Brief::STATUS_DRAFT    => Brief::STATUS_APPROVED,
            Brief::STATUS_APPROVED => Brief::STATUS_SENT,
        ];

        $target = $validated['status'];

        if (($allowed[$brief->status] ?? null) !== $target) {
            return response()->json([
                'message' => "Invalid status transition: {$brief->status} -> {$target}.",
            ], 422);
        }

        $brief->update(['status' => $target]);

        return response()->json(['brief' => $brief->fresh()->load('dossier:id,name,slug')]);
    }
}
