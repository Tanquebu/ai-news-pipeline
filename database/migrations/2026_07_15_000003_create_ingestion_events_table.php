<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ingestion_events', function (Blueprint $table) {
            $table->id();
            $table->string('source_system');
            $table->string('source_record_id');
            $table->char('content_hash', 64);
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status')->default('queued');
            $table->integer('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'source_record_id', 'content_hash']);
            $table->index('status');
        });

        DB::statement("ALTER TABLE ingestion_events ADD CONSTRAINT ingestion_events_status_check CHECK (status IN ('queued','processed','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_events');
    }
};
