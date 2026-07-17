<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\LLMClient;
use App\Models\Cluster;
use App\Models\Publication;
use App\Support\LlmJson;

class GenerateArticleAction
{
    private const MIN_ITEMS    = 3;
    private const MIN_ENTITIES = 2;

    public function __construct(private readonly LLMClient $llm) {}

    /**
     * @throws \RuntimeException if the cluster does not meet the eligibility heuristic
     */
    public function execute(Cluster $cluster): Publication
    {
        $cluster->loadMissing('newsItems');

        $itemCount   = $cluster->newsItems->count();
        $entityCount = $cluster->newsItems
            ->flatMap(fn ($item) => $item->entities ?? [])
            ->unique()
            ->count();

        if ($itemCount < self::MIN_ITEMS || $entityCount < self::MIN_ENTITIES) {
            throw new \RuntimeException(
                "Cluster #{$cluster->id} non idoneo per articolo (items: {$itemCount}, entità: {$entityCount})"
            );
        }

        $prompt = $this->buildPrompt($cluster);
        $raw    = $this->llm->complete($prompt, maxTokens: 2048);
        $data   = LlmJson::decode($raw);

        return Publication::create([
            'cluster_id'         => $cluster->id,
            'kind'               => 'article',
            'status'             => 'draft',
            'title'              => $data['title'] ?? $cluster->canonical_title,
            'body'               => $data['body']  ?? '',
            'generated_at'       => now(),
            'source_cluster_ids' => [$cluster->id],
        ]);
    }

    private function buildPrompt(Cluster $cluster): string
    {
        $items = $cluster->newsItems->map(fn ($item) => "- {$item->title}: {$item->summary}")->join("\n");

        return <<<PROMPT
        Sei un giornalista tecnologico specializzato in AI.

        CLUSTER: {$cluster->canonical_title}
        {$cluster->canonical_summary}

        NOTIZIE INCLUSE:
        {$items}

        Scrivi un articolo in italiano di 600-1000 parole, strutturato in markdown.
        Includi: titolo, introduzione, sviluppo con sezioni, conclusione con implicazioni pratiche.

        Rispondi con SOLO JSON valido:
        {"title": "Titolo dell'articolo", "body": "# Titolo\n\n..."}
        PROMPT;
    }
}
