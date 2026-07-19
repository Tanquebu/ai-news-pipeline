<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\PromoteTagProposalAction;
use App\Http\Controllers\Controller;
use App\Models\TagProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagProposalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TagProposal::where('status', 'pending')
            ->orderByDesc('frequency');

        if ($request->filled('q')) {
            $query->where('slug', 'ilike', '%' . $request->query('q') . '%');
        }

        return response()->json($query->paginate(20));
    }

    public function promote(TagProposal $tagProposal, PromoteTagProposalAction $action): JsonResponse
    {
        try {
            $tag = $action->execute($tagProposal);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($tag, 201);
    }
}
