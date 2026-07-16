<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DossierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Dossier extends Model
{
    /** @use HasFactory<DossierFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'document_count',
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
            'document_count' => 'integer',
        ];
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class)
            ->withPivot('similarity')
            ->withTimestamps();
    }
}
