<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\TagProposal;
use Illuminate\Http\JsonResponse;

class ScoringInfoController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'weights' => [
                'consensus'   => (float) config('pipeline.scoring.weight_consensus'),
                'novelty'     => (float) config('pipeline.scoring.weight_novelty'),
                'importance'  => (float) config('pipeline.scoring.weight_importance'),
                'topic_match' => (float) config('pipeline.scoring.weight_topic_match'),
            ],
            'consensus_saturation' => (int) config('pipeline.scoring.consensus_saturation'),
            'topic_interest_tags'  => config('pipeline.scoring.topic_interest_tags'),
            'tags'                 => Tag::orderBy('slug')->get(['slug', 'name', 'description']),
            'tag_proposals_count'  => TagProposal::where('status', 'pending')->count(),
        ]);
    }
}
