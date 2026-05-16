<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
    ];

    public function newsItems(): BelongsToMany
    {
        return $this->belongsToMany(NewsItem::class, 'news_item_tag');
    }
}
