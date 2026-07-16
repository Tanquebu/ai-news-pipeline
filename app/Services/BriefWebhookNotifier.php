<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brief;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Notifica di delivery post-generazione dei brief (T3.4).
 *
 * Se `pipeline.briefs.webhook_url` è configurato, invia un POST con il
 * riepilogo dei brief appena generati (id, dossier, titolo, score, tesi
 * breve, conteggi claim/fonti). La pipeline resta agnostica sul consumer:
 * oggi è un workflow n8n (`anp-digest`) che formatta e inoltra su Telegram,
 * domani potrebbe essere qualsiasi altro hook.
 *
 * Best-effort per contratto: nessuna configurazione o zero brief → no-op;
 * un fallimento HTTP viene loggato come warning e MAI propagato, perché la
 * notifica non deve far fallire una generazione già riuscita e persistita.
 */
class BriefWebhookNotifier
{
    /**
     * @param Collection<int, Brief> $briefs brief appena generati (status draft)
     */
    public function notify(Collection $briefs): void
    {
        $url = config('pipeline.briefs.webhook_url');

        if (empty($url) || $briefs->isEmpty()) {
            return;
        }

        $payload = [
            'event' => 'briefs.generated',
            'count'  => $briefs->count(),
            'briefs' => $briefs->map(fn (Brief $brief) => $this->summarize($brief))->values()->all(),
        ];

        try {
            Http::timeout(15)->post($url, $payload)->throw();
        } catch (\Throwable $e) {
            Log::warning('Brief webhook delivery failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Riepilogo compatto di un brief per il digest: abbastanza per decidere
     * se approvarlo, senza trasferire l'intero payload.
     *
     * @return array<string, mixed>
     */
    private function summarize(Brief $brief): array
    {
        $payload = $brief->payload ?? [];

        return [
            'id'            => $brief->id,
            'dossier'       => $brief->dossier?->slug ?? $brief->dossier?->name,
            'title'         => $brief->title,
            'score'         => $brief->score,
            'period_start'  => $brief->period_start?->toDateString(),
            'thesis'        => Str::limit((string) ($payload['thesis'] ?? ''), 400),
            'suggested_format' => $payload['suggested_format'] ?? null,
            'claims_count'  => is_array($payload['key_claims'] ?? null) ? count($payload['key_claims']) : 0,
            'sources_count' => is_array($payload['sources'] ?? null) ? count($payload['sources']) : 0,
        ];
    }
}
