<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\IngestReportAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class IngestReportsCommand extends Command
{
    protected $signature = 'reports:ingest
                            {--path= : Path to a .json file or directory (default: storage/reports/import)}
                            {--move= : Directory to move successfully ingested files into (default: storage/reports/ingested)}';

    protected $description = 'Ingest one or more AI report JSON files into the database';

    public function __construct(private readonly IngestReportAction $action)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path    = $this->option('path') ?? storage_path('reports/import');
        $moveDir = $this->option('move') ?? storage_path('reports/ingested');

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

        File::ensureDirectoryExists($moveDir);

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

                $wasCreated = $this->action->execute($payload);

                if ($wasCreated) {
                    $this->info('  Created.');
                    $created++;
                } else {
                    $this->line('  Skipped (already ingested).');
                    $skipped++;
                }

                $dest = rtrim($moveDir, '/') . '/' . $file->getBasename();
                File::move($filePath, $dest);
                $this->line("  Moved to: {$dest}");
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
