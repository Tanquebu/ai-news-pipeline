<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('news_item_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->constrained('news_items')->cascadeOnDelete();
            $table->string('name');
            $table->text('url');
            $table->smallInteger('position');
            $table->timestamps();

            $table->index(['news_item_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_item_sources');
    }
};
