<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DossierController extends Controller
{
    /**
     * Lista dei dossier con score spiegabile, breakdown e stato di
     * candidatura a brief. Consumato dai brief settimanali (T3.3/T3.4)
     * e dall'MCP server. Il centroide resta nascosto ($hidden sul model).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Dossier::query()
            ->orderByRaw('brief_score DESC NULLS LAST')
            ->orderBy('id');

        if ($request->boolean('candidates_only')) {
            $query->where('is_brief_candidate', true);
        }

        $dossiers = $query->get();

        return response()->json([
            'count'    => $dossiers->count(),
            'dossiers' => $dossiers,
        ]);
    }
}
