<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            // Score spiegabile del dossier (0..1) per la selezione dei brief
            // settimanali (T3.3). NULL = mai calcolato.
            $table->float('brief_score')->nullable();

            // Breakdown per componente (raw, normalized, weight, weighted_value)
            // + esito dei criteri di candidatura: la motivazione leggibile è
            // sempre ricostruibile da qui, lo score non è mai un numero opaco.
            $table->jsonb('score_breakdown')->nullable();

            // True se il dossier supera i criteri minimi di candidatura a
            // brief (>= N document e >= M fonti distinte nella finestra).
            $table->boolean('is_brief_candidate')->default(false);

            // Timestamp dell'ultimo calcolo dello score.
            $table->timestamp('scored_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropColumn(['brief_score', 'score_breakdown', 'is_brief_candidate', 'scored_at']);
        });
    }
};
