<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * I news_items nati dal flusso documents (ingest con category=news)
     * non appartengono a nessun report: report_id diventa nullable.
     * La FK con cascadeOnDelete resta invariata.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE news_items ALTER COLUMN report_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Reversibile solo se nel frattempo non sono nati news_items senza report.
        DB::statement('ALTER TABLE news_items ALTER COLUMN report_id SET NOT NULL');
    }
};
