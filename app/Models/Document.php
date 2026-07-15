<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'news_item_id',
        'source',
        'url',
        'url_hash',
        'title',
        'doc_type',
        'raw_path',
        'raw_hash',
        'mime',
        'lang',
        'summary',
        'extractor_version',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'news_item_id' => 'integer',
        ];
    }

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class)->orderBy('chunk_index');
    }
}
