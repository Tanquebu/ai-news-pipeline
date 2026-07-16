<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Indice GIN per la full-text search sui chunk documentali.
     *
     * Scelta della configurazione FTS: il corpus è misto italiano/inglese,
     * quindi uno stemmer language-specific ('english' o 'italian') degraderebbe
     * sistematicamente una delle due lingue. 'simple' tokenizza e minuscolizza
     * senza stemming né stopword: comportamento prevedibile e identico per
     * entrambe le lingue, al costo di non matchare le flessioni (search ≠
     * searching). Compensato dal ramo vettoriale della ricerca ibrida, che
     * copre le variazioni lessicali.
     *
     * L'espressione indicizzata DEVE combaciare con quella usata dalle query
     * di RagSearchService: to_tsvector('simple', content).
     */
    public function up(): void
    {
        DB::statement(
            "CREATE INDEX document_chunks_content_fts_idx
             ON document_chunks
             USING gin (to_tsvector('simple', content))"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS document_chunks_content_fts_idx');
    }
};
