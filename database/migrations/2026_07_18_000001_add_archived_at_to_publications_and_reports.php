<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('published_at');
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('ingested_at');
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
