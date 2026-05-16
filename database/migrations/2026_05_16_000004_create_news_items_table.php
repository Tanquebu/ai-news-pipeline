<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('section');
            $table->string('title');
            $table->text('summary');
            $table->jsonb('entities')->nullable();
            $table->date('event_date')->nullable();
            $table->jsonb('raw_tags')->nullable();
            $table->smallInteger('importance_self_rated')->nullable();
            $table->timestamps();

            $table->index('report_id');
            $table->index('event_date');
        });

        DB::statement("ALTER TABLE news_items ADD CONSTRAINT news_items_section_check CHECK (section IN ('strategic','technical','tooling'))");
        DB::statement('ALTER TABLE news_items ADD CONSTRAINT news_items_importance_check CHECK (importance_self_rated BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('news_items');
    }
};
