<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->nullable()->constrained('news_items')->nullOnDelete();
            $table->string('source')->default('intake');
            $table->text('url')->nullable();
            $table->char('url_hash', 64)->nullable()->unique();
            $table->string('title');
            $table->string('doc_type')->default('article');
            $table->text('raw_path')->nullable();
            $table->char('raw_hash', 64)->nullable();
            $table->string('mime')->nullable();
            $table->string('lang')->nullable();
            $table->text('summary')->nullable();
            $table->string('extractor_version')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index('status');
        });

        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_doc_type_check CHECK (doc_type IN ('article','pdf','note'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ('pending','chunked','embedded','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
