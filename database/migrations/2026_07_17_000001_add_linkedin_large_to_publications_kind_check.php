<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE publications DROP CONSTRAINT publications_kind_check');
        DB::statement("ALTER TABLE publications ADD CONSTRAINT publications_kind_check CHECK (kind IN ('linkedin_short','linkedin_medium','linkedin_opinion','linkedin_large','article'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE publications DROP CONSTRAINT publications_kind_check');
        DB::statement("ALTER TABLE publications ADD CONSTRAINT publications_kind_check CHECK (kind IN ('linkedin_short','linkedin_medium','linkedin_opinion','article'))");
    }
};
