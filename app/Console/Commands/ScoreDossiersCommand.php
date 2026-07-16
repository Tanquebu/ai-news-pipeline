<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Dossier;
use App\Services\DossierScoringService;
use Illuminate\Console\Command;

class ScoreDossiersCommand extends Command
{
    protected $signature = 'dossiers:score
        {--dry-run : Print scores and explanations without persisting}';

    protected $description = 'Calculate explainable brief scores and candidacy for all dossiers';

    public function handle(DossierScoringService $scoring): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $dossiers = Dossier::orderBy('id')->get();

        if ($dossiers->isEmpty()) {
            $this->warn('No dossiers found.');

            return self::SUCCESS;
        }

        $candidates = 0;

        foreach ($dossiers as $dossier) {
            $breakdown = $scoring->evaluate($dossier);

            if ($breakdown['candidacy']['is_candidate']) {
                $candidates++;
            }

            if ($dryRun) {
                $this->line("[dry-run] {$dossier->slug}");
                $this->line($scoring->explain($breakdown));
                continue;
            }

            $scoring->persist($dossier, $breakdown);

            $this->line(sprintf(
                '%s: score %.4f, %s',
                $dossier->slug,
                $breakdown['score'],
                $breakdown['candidacy']['is_candidate'] ? 'brief candidate' : 'not a candidate',
            ));
        }

        $prefix = $dryRun ? '[dry-run] Would score' : 'Scored';
        $this->info("{$prefix} {$dossiers->count()} dossier(s); {$candidates} brief candidate(s).");

        return self::SUCCESS;
    }
}
