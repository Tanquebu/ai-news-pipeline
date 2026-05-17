<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->nullable()->constrained('clusters')->nullOnDelete();
            $table->string('kind');
            $table->string('status')->default('draft');
            $table->string('title');
            $table->text('body');
            $table->jsonb('variants')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('published_at')->nullable();
            $table->jsonb('source_cluster_ids')->nullable();
            $table->timestamps();

            $table->index(['status', 'kind']);
            $table->index('cluster_id');
        });

        DB::statement("ALTER TABLE publications ADD CONSTRAINT publications_kind_check CHECK (kind IN ('linkedin_short','linkedin_medium','linkedin_opinion','article'))");
        DB::statement("ALTER TABLE publications ADD CONSTRAINT publications_status_check CHECK (status IN ('draft','approved','rejected','published'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
