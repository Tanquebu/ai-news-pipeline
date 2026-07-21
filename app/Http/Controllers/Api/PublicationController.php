<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePublicationRequest;
use App\Models\Publication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Publication::with('cluster.tags')
            ->orderByDesc('generated_at');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->query('kind'));
        }

        $request->boolean('archived')
            ? $query->whereNotNull('archived_at')
            : $query->whereNull('archived_at');

        return response()->json($query->paginate(20));
    }

    public function archive(Publication $publication): JsonResponse
    {
        $publication->update(['archived_at' => now()]);

        return response()->json($publication->fresh());
    }

    public function unarchive(Publication $publication): JsonResponse
    {
        $publication->update(['archived_at' => null]);

        return response()->json($publication->fresh());
    }

    public function update(UpdatePublicationRequest $request, Publication $publication): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['status']) && $data['status'] === 'published') {
            $data['published_at'] = now();
        }

        $publication->update($data);

        return response()->json($publication->fresh());
    }

    public function export(Publication $publication): Response
    {
        $filename = str($publication->title)->slug() . '.md';

        return response($publication->body, 200, [
            'Content-Type'        => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
