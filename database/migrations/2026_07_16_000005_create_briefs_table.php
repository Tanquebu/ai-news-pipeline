<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_id')->constrained()->cascadeOnDelete();

            // Inizio della settimana (lunedì) coperta dal brief. Insieme a
            // dossier_id è la chiave di idempotenza: al massimo un brief per
            // dossier per settimana, anche rilanciando briefs:generate.
            $table->date('period_start');

            $table->string('title');

            // Snapshot del brief_score del dossier al momento della
            // generazione: lo score sul dossier viene ricalcolato ogni notte,
            // qui resta la motivazione con cui il brief è stato selezionato.
            $table->float('score')->nullable();

            // Dossier informativo completo (formato roadmap v2 §3-M3): tesi,
            // claim con evidenze, controargomenti, claim rischiosi, formato
            // suggerito, angoli editoriali, fonti citabili con URL, why_now
            // (spiegazione dello score) e score_breakdown di selezione.
            $table->jsonb('payload');

            // Ciclo di vita: draft (generato) → approved (promosso a
            // contenuto, T3.4) → sent (esportato/consegnato).
            $table->string('status')->default('draft');

            $table->timestamps();

            $table->unique(['dossier_id', 'period_start']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('briefs');
    }
};
