<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Dossier;
use App\Services\DossierAssignmentService;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConsolidateDossiersCommand extends Command
{
    protected $signature = 'dossiers:consolidate
        {--dry-run : Show what would be done without making changes}';

    protected $description = 'Bootstrap missing dossier centroids, recalculate centroids from member documents, and retry assignment of orphan documents';

    public function handle(DossierAssignmentService $assignment, EmbeddingService $embeddings): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // 1. Bootstrap: i dossier senza centroide (appena seedati) ricevono
        //    un centroide provvisorio dall'embedding di nome + descrizione.
        //    Verrà rimpiazzato dall'embedding del primo document assegnato.
        $toBootstrap = Dossier::whereNull('centroid')->get();

        if ($dryRun) {
            $this->info("[dry-run] Would bootstrap {$toBootstrap->count()} centroid(s) from description.");
        } else {
            foreach ($toBootstrap as $dossier) {
                $vector = $embeddings->embedText(
                    trim($dossier->name . "\n" . ($dossier->description ?? ''))
                );

                DB::update(
                    'UPDATE dossiers SET centroid = ?, updated_at = ? WHERE id = ? AND centroid IS NULL',
                    ['[' . implode(',', $vector) . ']', now(), $dossier->id],
                );
            }

            $this->info("Bootstrapped {$toBootstrap->count()} centroid(s) from description.");
        }

        // 2. Ricalcolo esatto: per ogni dossier con document assegnati, il
        //    centroide torna alla media (per-document) degli embedding dei
        //    membri e document_count viene riallineato al pivot. Corregge la
        //    deriva della media incrementale e i conteggi stale (es. dopo
        //    delete a cascata di un document).
        $memberDossierIds = DB::table('document_dossier')->distinct()->pluck('dossier_id');

        if ($dryRun) {
            $this->info("[dry-run] Would recalculate {$memberDossierIds->count()} centroid(s) from member documents.");
        } else {
            foreach ($memberDossierIds as $dossierId) {
                $centroid = DB::scalar(
                    'SELECT avg(doc_embedding)::text FROM (
                        SELECT avg(dc.embedding) AS doc_embedding
                        FROM document_dossier dd
                        JOIN document_chunks dc ON dc.document_id = dd.document_id
                        WHERE dd.dossier_id = ? AND dc.embedding IS NOT NULL
                        GROUP BY dd.document_id
                    ) per_document',
                    [$dossierId],
                );

                $count = DB::table('document_dossier')->where('dossier_id', $dossierId)->count();

                if ($centroid !== null) {
                    DB::update(
                        'UPDATE dossiers SET centroid = ?, document_count = ?, updated_at = ? WHERE id = ?',
                        [$centroid, $count, now(), $dossierId],
                    );
                } else {
                    DB::update(
                        'UPDATE dossiers SET document_count = ?, updated_at = ? WHERE id = ?',
                        [$count, now(), $dossierId],
                    );
                }
            }

            Dossier::whereNotIn('id', $memberDossierIds)
                ->where('document_count', '>', 0)
                ->update(['document_count' => 0]);

            $this->info("Recalculated {$memberDossierIds->count()} centroid(s) from member documents.");
        }

        // 3. Orfani: document embedded senza alcun dossier — riprova
        //    l'assegnazione con i centroidi appena consolidati.
        $orphans = Document::where('status', 'embedded')
            ->whereDoesntHave('dossiers')
            ->orderBy('id')
            ->get();

        if ($dryRun) {
            $this->info("[dry-run] Would retry assignment for {$orphans->count()} orphan document(s).");

            return self::SUCCESS;
        }

        $assigned = 0;

        foreach ($orphans as $document) {
            if ($assignment->assign($document) !== null) {
                $assigned++;
            }
        }

        $stillOrphan = $orphans->count() - $assigned;

        $this->info("Assigned {$assigned} of {$orphans->count()} orphan document(s); {$stillOrphan} still below threshold.");

        return self::SUCCESS;
    }
}
