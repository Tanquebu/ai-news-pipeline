<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\IngestDocumentAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    public function ingest(Request $request, IngestDocumentAction $action): JsonResponse
    {
        try {
            $result = $action->execute($request->all());
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->implode(' ');

            return response()->json(['error' => $message], 422);
        }

        return response()->json($result, 202);
    }
}
