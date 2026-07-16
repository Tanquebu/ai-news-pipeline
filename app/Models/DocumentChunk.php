<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DocumentChunkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    /** @use HasFactory<DocumentChunkFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id',
        'chunk_index',
        'content',
        'token_count',
        'metadata',
    ];

    /**
     * L'embedding (vector 1536d, colonna aggiunta via SQL raw) non deve
     * mai comparire nelle serializzazioni JSON dei payload API.
     */
    protected $hidden = [
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'chunk_index' => 'integer',
            'token_count' => 'integer',
            'metadata'    => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
