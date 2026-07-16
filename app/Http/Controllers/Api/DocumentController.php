<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\IngestDocumentAction;
use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    public function show(Document $document): JsonResponse
    {
        // Select esplicita sui chunk: la colonna embedding (vector 1536d)
        // non deve mai finire nel payload né essere trasferita dal DB.
        $document->load(['chunks' => fn ($query) => $query->select([
            'id',
            'document_id',
            'chunk_index',
            'content',
            'token_count',
            'metadata',
            'created_at',
            'updated_at',
        ])]);

        return response()->json(['document' => $document]);
    }

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
