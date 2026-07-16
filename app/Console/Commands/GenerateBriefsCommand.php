<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BriefGenerationService;
use Illuminate\Console\Command;

class GenerateBriefsCommand extends Command
{
    protected $signature = 'briefs:generate
        {--limit= : Max briefs to generate this run (default: pipeline.briefs.max_per_run)}
        {--dry-run : Show selected dossiers and synthesis input without calling the LLM or writing}';

    protected $description = 'Generate weekly editorial briefs from the top-scoring candidate dossiers';

    public function handle(BriefGenerationService $briefs): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Cap per run dalla roadmap v2 (max 3-5 brief a settimana): tiene
        // sotto controllo il costo di sintesi e il rumore editoriale.
        $limit = max(1, (int) ($this->option('limit') ?? config('pipeline.briefs.max_per_run', 3)));

        $periodStart = $briefs->periodStart();

        $candidates = $briefs->candidates($periodStart, $limit);

        if ($candidates->isEmpty()) {
            $this->info("No candidate dossiers without a brief for the week of {$periodStart->toDateString()}.");

            return self::SUCCESS;
        }

        $generated = 0;
        $failures  = 0;

        foreach ($candidates as $dossier) {
            $documents = $briefs->topDocuments($dossier);

            if ($documents->isEmpty()) {
                // Candidato allo scoring ma senza document nella finestra al
                // momento della generazione (es. document cancellati): niente
                // materiale, niente chiamata LLM.
                $this->warn("{$dossier->slug}: no documents in the scoring window, skipped.");
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] %s (score %s) — would synthesize from %d document(s):',
                    $dossier->slug,
                    $dossier->brief_score === null ? 'n/a' : number_format($dossier->brief_score, 4),
                    $documents->count(),
                ));

                foreach ($documents as $document) {
                    $this->line("  - {$document->title} ({$document->source}) {$document->url}");
                }

                if ($this->output->isVerbose()) {
                    $this->line($briefs->buildPrompt($dossier, $documents));
                }

                continue;
            }

            try {
                $brief = $briefs->generate($dossier, $periodStart, $documents);
            } catch (\Throwable $e) {
                // Un dossier fallito (API giù, JSON non valido) non deve
                // bloccare i brief degli altri candidati del run.
                $failures++;
                $this->error("{$dossier->slug}: {$e->getMessage()}");
                continue;
            }

            $generated++;
            $this->line("{$dossier->slug}: brief #{$brief->id} \"{$brief->title}\"");
        }

        if ($dryRun) {
            $this->info("[dry-run] Would generate up to {$candidates->count()} brief(s) for the week of {$periodStart->toDateString()}.");

            return self::SUCCESS;
        }

        $this->info("Generated {$generated} brief(s) for the week of {$periodStart->toDateString()}; {$failures} failure(s).");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
