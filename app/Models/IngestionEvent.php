<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IngestionEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionEvent extends Model
{
    /** @use HasFactory<IngestionEventFactory> */
    use HasFactory;

    protected $fillable = [
        'source_system',
        'source_record_id',
        'content_hash',
        'document_id',
        'status',
        'attempts',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'attempts'    => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
