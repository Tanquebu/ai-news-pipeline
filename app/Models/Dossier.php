<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DossierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dossier extends Model
{
    /** @use HasFactory<DossierFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'document_count',
        'brief_score',
        'score_breakdown',
        'is_brief_candidate',
        'scored_at',
    ];

    /**
     * Il centroide (vector 1536d, colonna aggiunta via SQL raw) non deve
     * mai comparire nelle serializzazioni JSON dei payload API.
     */
    protected $hidden = [
        'centroid',
    ];

    protected function casts(): array
    {
        return [
            'document_count'     => 'integer',
            'brief_score'        => 'float',
            'score_breakdown'    => 'array',
            'is_brief_candidate' => 'boolean',
            'scored_at'          => 'datetime',
        ];
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class)
            ->withPivot('similarity')
            ->withTimestamps();
    }

    public function briefs(): HasMany
    {
        return $this->hasMany(Brief::class);
    }
}
