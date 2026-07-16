<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_dossier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            // Similarità coseno document↔centroide al momento dell'assegnazione:
            // serve alla spiegabilità (T3.2) e al debugging delle soglie.
            $table->float('similarity')->nullable();
            $table->timestamps();

            // Lo stesso document non può entrare due volte nello stesso dossier.
            $table->unique(['document_id', 'dossier_id']);
            $table->index('dossier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_dossier');
    }
};
