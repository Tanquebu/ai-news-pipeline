<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsItem extends Model
{
    protected $fillable = [
        'report_id',
        'cluster_id',
        'section',
        'title',
        'summary',
        'entities',
        'event_date',
        'raw_tags',
        'importance_self_rated',
    ];

    protected function casts(): array
    {
        return [
            'section'               => ReportSection::class,
            'entities'              => 'array',
            'event_date'            => 'date',
            'raw_tags'              => 'array',
            'importance_self_rated' => 'integer',
            'cluster_id'            => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(NewsItemSource::class)->orderBy('position');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'news_item_tag');
    }

    public function resolvedEntities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'news_item_entity');
    }
}
