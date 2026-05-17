<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Cluster extends Model
{
    protected $fillable = [
        'canonical_title',
        'canonical_summary',
        'first_seen_at',
        'last_seen_at',
        'consensus_count',
        'novelty_score',
        'importance_avg',
        'topic_match_score',
        'total_score',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at'   => 'datetime',
            'last_seen_at'    => 'datetime',
            'consensus_count' => 'integer',
        ];
    }

    public function newsItems(): HasMany
    {
        return $this->hasMany(NewsItem::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'cluster_tag');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }
}
