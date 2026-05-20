<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\LLMClient;
use App\Models\Cluster;
use App\Models\Tag;
use App\Models\TagProposal;
use App\Services\ScoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SynthesizeClusterJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    /** Backoff in seconds: 1m, 5m, 15m, 30m, 1h, 2h, 4h */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600, 7200, 14400];
    }

    public function __construct(public readonly int $clusterId) {}

    public function handle(LLMClient $llm, ScoringService $scoring): void
    {
        $cluster = Cluster::with(['newsItems', 'tags'])->findOrFail($this->clusterId);

        $allTagSlugs = Tag::pluck('slug')->all();
        $prompt      = $this->buildPrompt($cluster, $allTagSlugs);

        $raw  = $llm->complete($prompt, maxTokens: 1024);
        $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        $cluster->update([
            'canonical_title'   => $data['canonical_title'],
            'canonical_summary' => $data['canonical_summary'],
            'novelty_score'     => (float) ($data['novelty_score'] ?? 0.0),
        ]);

        $validSlugs = array_intersect($data['tags'] ?? [], $allTagSlugs);
        $tagIds     = Tag::whereIn('slug', $validSlugs)->pluck('id')->all();
        $cluster->tags()->sync($tagIds);

        foreach ($data['tag_proposals'] ?? [] as $proposal) {
            if (in_array($proposal['slug'], $allTagSlugs, true)) {
                continue;
            }

            $record = TagProposal::firstOrCreate(
                ['slug' => $proposal['slug']],
                ['reason' => $proposal['reason'], 'status' => 'pending'],
            );

            if (! $record->wasRecentlyCreated) {
                $record->increment('frequency');
            }
        }

        $cluster->load(['newsItems', 'tags']);
        $scoring->updateScore($cluster);
    }

    private function buildPrompt(Cluster $cluster, array $allTagSlugs): string
    {
        $items = $cluster->newsItems->map(fn ($item) => implode("\n", [
            "- [{$item->section->value}] {$item->title}",
            "  {$item->summary}",
            '  Entità: ' . implode(', ', $item->entities ?? []),
            '  Tag grezzi: ' . implode(', ', $item->raw_tags ?? []),
        ]))->join("\n");

        $tagList = implode(', ', $allTagSlugs);
        $count   = $cluster->newsItems->count();

        return <<<PROMPT
        Sei un assistente che sintetizza cluster di notizie AI provenienti da più fonti.

        NOTIZIE DEL CLUSTER ({$count} item):
        {$items}

        TAG DISPONIBILI (usa SOLO questi slug, max 5):
        {$tagList}

        Restituisci ESCLUSIVAMENTE un oggetto JSON valido con questi campi:
        {
          "canonical_title": "titolo sintetico del cluster",
          "canonical_summary": "2-4 frasi in italiano che sintetizzano il consenso tra le fonti",
          "tags": ["slug1", "slug2"],
          "tag_proposals": [{"slug": "nuovo-slug", "reason": "motivazione"}],
          "novelty_score": 0.0
        }

        REGOLE:
        - "tags" DEVE contenere SOLO slug dall'elenco TAG DISPONIBILI (max 5)
        - Nuovi concetti di tag vanno ESCLUSIVAMENTE in "tag_proposals" con reason motivata
        - "novelty_score" è 0.0–1.0: quanto questa notizia è rilevante e originale
        - Rispondi con SOLO JSON valido, nessun markdown, nessun testo extra
        PROMPT;
    }
}
