<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TagProposal extends Model
{
    protected $fillable = [
        'slug',
        'reason',
        'frequency',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => 'integer',
        ];
    }
}
