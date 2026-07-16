<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Brief;
use App\Models\Dossier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brief>
 */
class BriefFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dossier_id'   => Dossier::factory(),
            'period_start' => now()->startOfWeek()->toDateString(),
            'title'        => fake()->sentence(6),
            'score'        => fake()->randomFloat(4, 0, 1),
            'payload'      => [
                'theme'            => fake()->words(3, true),
                'thesis'           => fake()->paragraph(),
                'key_claims'       => [
                    ['claim' => fake()->sentence(), 'source_urls' => [fake()->url()]],
                ],
                'counterarguments' => [fake()->sentence()],
                'risky_claims'     => [],
                'suggested_format' => 'linkedin-post',
                'editorial_angles' => [fake()->sentence()],
                'why_now'          => fake()->sentence(),
                'sources'          => [
                    ['title' => fake()->sentence(4), 'url' => fake()->url(), 'source' => 'intake'],
                ],
            ],
            'status'       => Brief::STATUS_DRAFT,
        ];
    }
}
