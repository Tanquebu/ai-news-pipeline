<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->unique()->url();

        return [
            'news_item_id'      => null,
            'source'            => 'intake',
            'url'               => $url,
            'url_hash'          => hash('sha256', $url),
            'title'             => fake()->sentence(6),
            'doc_type'          => 'article',
            'raw_path'          => null,
            'raw_hash'          => null,
            'mime'              => 'text/html',
            'lang'              => 'en',
            'summary'           => fake()->paragraph(),
            'extractor_version' => null,
            'status'            => 'pending',
        ];
    }
}
