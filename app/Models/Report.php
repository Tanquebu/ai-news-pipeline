<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    protected $fillable = [
        'report_date',
        'source_ai',
        'payload',
        'payload_hash',
        'ingested_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date'  => 'date',
            'payload'      => 'array',
            'ingested_at'  => 'datetime',
            'archived_at'  => 'datetime',
        ];
    }

    public function newsItems(): HasMany
    {
        return $this->hasMany(NewsItem::class);
    }
}
