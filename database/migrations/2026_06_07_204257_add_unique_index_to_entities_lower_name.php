<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX entities_name_unique ON entities (lower(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS entities_name_unique');
    }
};
