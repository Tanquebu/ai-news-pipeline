<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Publication extends Model
{
    protected $fillable = [
        'cluster_id',
        'kind',
        'status',
        'title',
        'body',
        'variants',
        'generated_at',
        'published_at',
        'source_cluster_ids',
    ];

    protected function casts(): array
    {
        return [
            'variants'           => 'array',
            'source_cluster_ids' => 'array',
            'generated_at'       => 'datetime',
            'published_at'       => 'datetime',
        ];
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }
}
