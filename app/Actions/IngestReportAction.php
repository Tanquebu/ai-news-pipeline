<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Entity;
use App\Models\NewsItem;
use App\Models\NewsItemSource;
use App\Models\Report;
use App\Models\Tag;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IngestReportAction
{
    /**
     * @return bool true if ingested, false if skipped (duplicate)
     * @throws ValidationException
     */
    public function execute(array $payload): bool
    {
        $hash = CanonicalJson::hash($payload);

        if (Report::where('payload_hash', $hash)->exists()) {
            return false;
        }

        Validator::make($payload, $this->rules())->validate();

        DB::transaction(function () use ($payload, $hash) {
            $report = Report::create([
                'report_date'  => $payload['report_date'],
                'source_ai'    => $payload['source_ai'],
                'payload'      => $payload,
                'payload_hash' => $hash,
                'ingested_at'  => now(),
            ]);

            foreach ($payload['items'] as $item) {
                $this->ingestItem($report, $item);
            }
        });

        return true;
    }

    private function rules(): array
    {
        return [
            'report_date'                   => ['required', 'date_format:Y-m-d'],
            'source_ai'                     => ['required', 'string'],
            'items'                         => ['required', 'array'],
            'items.*.section'               => ['required', 'string', 'in:strategic,technical,tooling'],
            'items.*.title'                 => ['required', 'string'],
            'items.*.summary'               => ['required', 'string'],
            'items.*.entities'              => ['present', 'array'],
            'items.*.entities.*'            => ['string'],
            'items.*.event_date'            => ['nullable', 'date_format:Y-m-d'],
            'items.*.sources'               => ['present', 'array'],
            'items.*.sources.*.name'        => ['required', 'string'],
            'items.*.sources.*.url'         => ['required', 'url'],
            'items.*.importance_self_rated' => ['nullable', 'integer', 'between:1,5'],
            'items.*.raw_tags'              => ['present', 'array'],
            'items.*.raw_tags.*'            => ['string'],
        ];
    }

    private function ingestItem(Report $report, array $item): void
    {
        $newsItem = NewsItem::create([
            'report_id'             => $report->id,
            'section'               => $item['section'],
            'title'                 => $item['title'],
            'summary'               => $item['summary'],
            'entities'              => $item['entities'],
            'event_date'            => $item['event_date'] ?? null,
            'raw_tags'              => $item['raw_tags'],
            'importance_self_rated' => $item['importance_self_rated'] ?? null,
        ]);

        foreach ($item['sources'] as $position => $source) {
            NewsItemSource::create([
                'news_item_id' => $newsItem->id,
                'name'         => $source['name'],
                'url'          => $source['url'],
                'position'     => $position,
            ]);
        }

        foreach ($item['entities'] as $entityName) {
            $entity = Entity::firstOrCreate(
                ['name' => $entityName],
                ['type' => 'other'],
            );
            $newsItem->resolvedEntities()->attach($entity->id);
        }

        $tagIds = collect($item['raw_tags'])
            ->map(fn(string $raw) => Str::slug(strtolower($raw)))
            ->unique()
            ->map(fn(string $slug) => Tag::where('slug', $slug)->value('id'))
            ->filter()
            ->values()
            ->all();

        if ($tagIds !== []) {
            $newsItem->tags()->attach($tagIds);
        }
    }
}
