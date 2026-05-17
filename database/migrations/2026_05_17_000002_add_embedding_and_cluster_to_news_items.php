<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $dimensions = (int) config('pipeline.embedding.dimensions', 1536);

        DB::statement("ALTER TABLE news_items ADD COLUMN embedding vector({$dimensions})");
        DB::statement('CREATE INDEX news_items_embedding_idx ON news_items USING hnsw (embedding vector_cosine_ops)');

        Schema::table('news_items', function (Blueprint $table) {
            $table->foreignId('cluster_id')->nullable()->constrained('clusters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropForeign(['cluster_id']);
            $table->dropColumn('cluster_id');
        });

        DB::statement('DROP INDEX IF EXISTS news_items_embedding_idx');
        DB::statement('ALTER TABLE news_items DROP COLUMN IF EXISTS embedding');
    }
};
