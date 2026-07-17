<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\LLMClient;
use App\Models\Cluster;
use App\Models\Publication;
use App\Support\LlmJson;

class GenerateLinkedInPostsAction
{
    public function __construct(private readonly LLMClient $llm) {}

    /** @return Publication[] */
    public function execute(Cluster $cluster): array
    {
        $cluster->loadMissing(['newsItems', 'tags']);

        $prompt = $this->buildPrompt($cluster);
        $raw    = $this->llm->complete($prompt, maxTokens: 1024);
        $data   = LlmJson::decode($raw);

        $now   = now();
        $kinds = [
            'linkedin_short'   => $data['short']   ?? '',
            'linkedin_medium'  => $data['medium']  ?? '',
            'linkedin_opinion' => $data['opinion'] ?? '',
        ];

        $publications = [];

        foreach ($kinds as $kind => $body) {
            $publications[] = Publication::create([
                'cluster_id'         => $cluster->id,
                'kind'               => $kind,
                'status'             => 'draft',
                'title'              => $cluster->canonical_title ?? '',
                'body'               => $body,
                'generated_at'       => $now,
                'source_cluster_ids' => [$cluster->id],
            ]);
        }

        return $publications;
    }

    private function buildPrompt(Cluster $cluster): string
    {
        $tags = $cluster->tags->pluck('name')->join(', ');

        return <<<PROMPT
        Sei un copywriter esperto di AI e tecnologia per LinkedIn.

        CLUSTER DI NOTIZIE: {$cluster->canonical_title}
        {$cluster->canonical_summary}
        TAG: {$tags}

        Genera 3 versioni di post LinkedIn in italiano:
        - "short": max 200 caratteri, impatto immediato, emoji benvenuto
        - "medium": 300-500 caratteri, contesto e takeaway principale
        - "opinion": 300-500 caratteri, prospettiva editoriale personale

        Rispondi con SOLO JSON valido:
        {"short": "...", "medium": "...", "opinion": "..."}
        PROMPT;
    }
}
