<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RagSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RagSearchController extends Controller
{
    public function search(Request $request, RagSearchService $service): JsonResponse
    {
        $validator = Validator::make($request->query->all(), [
            'q'        => ['required', 'string', 'max:1000'],
            'limit'    => ['nullable', 'integer', 'min:1', 'max:50'],
            'doc_type' => ['nullable', 'string', 'in:article,pdf,note'],
            'source'   => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $data = $validator->validate();
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->implode(' ');

            return response()->json(['error' => $message], 422);
        }

        $results = $service->search(
            $data['q'],
            (int) ($data['limit'] ?? 10),
            $data['doc_type'] ?? null,
            $data['source'] ?? null,
        );

        return response()->json([
            'query'   => $data['q'],
            'count'   => count($results),
            'results' => $results,
        ]);
    }
}
