<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clusters', function (Blueprint $table) {
            $table->id();
            $table->string('canonical_title');
            $table->text('canonical_summary')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('consensus_count')->default(1);
            $table->float('novelty_score')->nullable();
            $table->float('importance_avg')->nullable();
            $table->float('topic_match_score')->nullable();
            $table->float('total_score')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('status');
            $table->index('last_seen_at');
            $table->index('total_score');
        });

        DB::statement("ALTER TABLE clusters ADD CONSTRAINT clusters_status_check CHECK (status IN ('active','archived'))");

        Schema::create('cluster_tag', function (Blueprint $table) {
            $table->foreignId('cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['cluster_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_tag');
        Schema::dropIfExists('clusters');
    }
};
