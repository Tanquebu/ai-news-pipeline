<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Tag;
use App\Models\TagProposal;
use Illuminate\Support\Str;

class PromoteTagProposalAction
{
    public function execute(TagProposal $proposal): Tag
    {
        if ($proposal->status !== 'pending') {
            throw new \RuntimeException('Tag proposal is not pending.');
        }

        if (Tag::where('slug', $proposal->slug)->exists()) {
            throw new \RuntimeException('A tag with this slug already exists.');
        }

        $tag = Tag::create([
            'slug'        => $proposal->slug,
            'name'        => Str::title(str_replace('-', ' ', $proposal->slug)),
            'description' => $proposal->reason,
        ]);

        $proposal->update(['status' => 'approved']);

        return $tag;
    }
}
