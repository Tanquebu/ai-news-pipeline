<?php

return [

    'embedding' => [
        'driver'     => env('EMBEDDING_DRIVER', 'openai'),
        'model'      => env('EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 1536),
    ],

    'clustering' => [
        'similarity_threshold' => (float) env('CLUSTERING_SIMILARITY_THRESHOLD', 0.85),
        'time_window_hours'    => (int) env('CLUSTERING_TIME_WINDOW_HOURS', 72),
    ],

    'api_token' => env('PIPELINE_API_TOKEN'),

    'dossier' => [
        // Similarità coseno minima document↔centroide per l'assegnazione
        // automatica a un dossier. Più bassa della soglia di clustering
        // (0.85, near-duplicate): qui si cerca affinità tematica, non la
        // stessa notizia. Da tarare sul corpus reale via env.
        'similarity_threshold' => (float) env('DOSSIER_SIMILARITY_THRESHOLD', 0.45),

        // Scoring spiegabile dei dossier per la candidatura ai brief
        // settimanali (DossierScoringService, comando dossiers:score).
        'scoring' => [
            // Finestra di attività: volume e diversità fonti contano solo i
            // document ingestati (created_at) negli ultimi N giorni.
            'window_days' => (int) env('DOSSIER_SCORING_WINDOW_DAYS', 30),

            // Saturazione del volume: oltre questa soglia di document nella
            // finestra la componente vale 1.0. Evita che un dossier
            // sbilanciato (catch-all) domini linearmente lo score.
            'volume_saturation' => (int) env('DOSSIER_SCORING_VOLUME_SATURATION', 10),

            // Saturazione della diversità fonti: N fonti distinte → 1.0.
            'diversity_saturation' => (int) env('DOSSIER_SCORING_DIVERSITY_SATURATION', 4),

            // Recency con decadimento esponenziale: la componente vale 0.5
            // quando l'ultimo document risale a "half_life" giorni fa.
            'recency_half_life_days' => (float) env('DOSSIER_SCORING_RECENCY_HALF_LIFE_DAYS', 7),

            // Pesi delle componenti (somma attesa 1.0, non forzata).
            'weight_volume'    => (float) env('DOSSIER_SCORING_WEIGHT_VOLUME', 0.35),
            'weight_diversity' => (float) env('DOSSIER_SCORING_WEIGHT_DIVERSITY', 0.25),
            'weight_recency'   => (float) env('DOSSIER_SCORING_WEIGHT_RECENCY', 0.25),
            'weight_cohesion'  => (float) env('DOSSIER_SCORING_WEIGHT_COHESION', 0.15),

            // Criteri minimi di candidatura a brief, valutati sulla finestra:
            // almeno N document e almeno M fonti distinte (campo source).
            'candidate_min_documents' => (int) env('DOSSIER_CANDIDATE_MIN_DOCUMENTS', 3),
            'candidate_min_sources'   => (int) env('DOSSIER_CANDIDATE_MIN_SOURCES', 2),
        ],
    ],

    'briefs' => [
        // Cap per run di briefs:generate, dalla roadmap v2 (max 3-5 brief a
        // settimana): limita il costo di sintesi e il rumore editoriale.
        // Override puntuale con --limit.
        'max_per_run' => (int) env('BRIEFS_MAX_PER_RUN', 3),

        // Quanti document per dossier (i più affini al centroide, poi i più
        // recenti, nella finestra di scoring) entrano nel prompt di sintesi
        // e nelle fonti citabili del brief.
        'top_documents' => (int) env('BRIEFS_TOP_DOCUMENTS', 8),

        // Budget di output della sintesi: il payload del brief (claim con
        // fonti, controargomenti, angoli) è molto più ricco della synthesis
        // dei cluster — 2048 token lo troncavano producendo JSON invalido.
        'max_tokens' => (int) env('BRIEFS_MAX_TOKENS', 4096),

        // Webhook di delivery post-generazione (T3.4): se configurato,
        // briefs:generate POST-a un riepilogo dei brief generati (id, dossier,
        // titolo, tesi, conteggi). La pipeline resta agnostica sul consumer
        // (oggi un workflow n8n che inoltra su Telegram). Default null =
        // notifica disabilitata; un fallimento del webhook non fa mai fallire
        // la generazione.
        'webhook_url' => env('BRIEFS_WEBHOOK_URL'),
    ],

    'cluster' => [
        'feed_window_days'   => (int) env('CLUSTER_FEED_WINDOW_DAYS', 14),
        'archive_after_days' => (int) env('CLUSTER_ARCHIVE_AFTER_DAYS', 14),
    ],

    'scoring' => [
        // Saturazione del consenso: consensus_count >= N → componente 1.0.
        // Default 10 per retrocompatibilità (nato con ~5 report AI/giorno,
        // dove il consenso misurava l'accordo tra fonti indipendenti). Con
        // l'ingestione da intake — fonte quasi unica — il consenso va
        // riletto come "quante volte ho salvato materiale sullo stesso
        // tema" (interesse ricorrente personale): in prod si consiglia 3.
        'consensus_saturation' => (int) env('SCORING_CONSENSUS_SATURATION', 10),

        'weight_consensus'   => (float) env('SCORING_WEIGHT_CONSENSUS', 0.35),
        'weight_novelty'     => (float) env('SCORING_WEIGHT_NOVELTY', 0.20),
        'weight_importance'  => (float) env('SCORING_WEIGHT_IMPORTANCE', 0.20),
        'weight_topic_match' => (float) env('SCORING_WEIGHT_TOPIC_MATCH', 0.25),
        'topic_interest_tags' => array_filter(explode(',', env('SCORING_TOPIC_INTEREST_TAGS', 'mcp,agentic-frameworks,coding-tools'))),
    ],

];
