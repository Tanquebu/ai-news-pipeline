<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->integer('chunk_index');
            $table->text('content');
            $table->integer('token_count')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
        });

        $dimensions = (int) config('pipeline.embedding.dimensions', 1536);

        DB::statement("ALTER TABLE document_chunks ADD COLUMN embedding vector({$dimensions})");
        DB::statement('CREATE INDEX document_chunks_embedding_idx ON document_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
