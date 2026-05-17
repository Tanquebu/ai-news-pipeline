<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\IngestReportAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class IngestReportsCommand extends Command
{
    protected $signature = 'reports:ingest {path : Path to a .json file or a directory of .json files}';

    protected $description = 'Ingest one or more AI report JSON files into the database';

    public function __construct(private readonly IngestReportAction $action)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("Path not found: {$path}");

            return self::FAILURE;
        }

        $files = is_dir($path)
            ? collect(File::files($path))->filter(fn ($f) => $f->getExtension() === 'json')->values()
            : collect([new \SplFileInfo($path)]);

        if ($files->isEmpty()) {
            $this->warn('No JSON files found.');

            return self::SUCCESS;
        }

        $created = $skipped = $errors = 0;

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $this->line("Processing: {$filePath}");

            try {
                $payload = json_decode(
                    file_get_contents($filePath),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                );

                if ($this->action->execute($payload)) {
                    $this->info('  Created.');
                    $created++;
                } else {
                    $this->line('  Skipped (already ingested).');
                    $skipped++;
                }
            } catch (ValidationException $e) {
                $this->error('  Validation error: ' . implode(', ', $e->validator->errors()->all()));
                $errors++;
            } catch (\JsonException $e) {
                $this->error("  JSON parse error: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->line("Done. Created: {$created} | Skipped: {$skipped} | Errors: {$errors}");

        return self::SUCCESS;
    }
}
