<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Services\DossierAssignmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AssignDocumentToDossierJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $documentId) {}

    public function handle(DossierAssignmentService $service): void
    {
        $document = Document::findOrFail($this->documentId);

        if ($document->status !== 'embedded') {
            return;
        }

        // Sotto soglia il document resta orfano: ci riprova il
        // consolidamento notturno (dossiers:consolidate). Un fallimento
        // qui non tocca lo status del document, che è già embedded.
        $service->assign($document);
    }
}
