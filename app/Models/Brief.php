<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BriefFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Brief extends Model
{
    /** @use HasFactory<BriefFactory> */
    use HasFactory;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_SENT = 'sent';

    protected $fillable = [
        'dossier_id',
        'period_start',
        'title',
        'score',
        'payload',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'dossier_id'   => 'integer',
            'period_start' => 'date',
            'score'        => 'float',
            'payload'      => 'array',
        ];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }
}
