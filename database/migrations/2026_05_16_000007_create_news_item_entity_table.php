<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('news_item_entity', function (Blueprint $table) {
            $table->foreignId('news_item_id')->constrained('news_items')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->primary(['news_item_id', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_item_entity');
    }
};
