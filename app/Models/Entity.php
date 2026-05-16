<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Entity extends Model
{
    protected $fillable = [
        'name',
        'type',
    ];

    public function newsItems(): BelongsToMany
    {
        return $this->belongsToMany(NewsItem::class, 'news_item_entity');
    }
}
