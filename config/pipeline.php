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
    ],

    'cluster' => [
        'feed_window_days'   => (int) env('CLUSTER_FEED_WINDOW_DAYS', 14),
        'archive_after_days' => (int) env('CLUSTER_ARCHIVE_AFTER_DAYS', 14),
    ],

    'scoring' => [
        'weight_consensus'   => (float) env('SCORING_WEIGHT_CONSENSUS', 0.35),
        'weight_novelty'     => (float) env('SCORING_WEIGHT_NOVELTY', 0.20),
        'weight_importance'  => (float) env('SCORING_WEIGHT_IMPORTANCE', 0.20),
        'weight_topic_match' => (float) env('SCORING_WEIGHT_TOPIC_MATCH', 0.25),
        'topic_interest_tags' => array_filter(explode(',', env('SCORING_TOPIC_INTEREST_TAGS', 'mcp,agentic-frameworks,coding-tools'))),
    ],

];
