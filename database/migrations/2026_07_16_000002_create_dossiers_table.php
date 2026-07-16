<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dossiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('document_count')->default(0);
            $table->timestamps();
        });

        $dimensions = (int) config('pipeline.embedding.dimensions', 1536);

        // Centroide tematico del dossier: parte NULL, viene popolato dal
        // bootstrap (embedding della descrizione) o dalla prima assegnazione.
        // Niente indice HNSW: i dossier sono poche decine di righe, il
        // confronto in seq scan è già ottimale.
        DB::statement("ALTER TABLE dossiers ADD COLUMN centroid vector({$dimensions})");
    }

    public function down(): void
    {
        Schema::dropIfExists('dossiers');
    }
};
