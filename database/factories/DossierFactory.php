<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Dossier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Dossier>
 */
class DossierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name'           => $name,
            'slug'           => Str::slug($name),
            'description'    => fake()->sentence(),
            'document_count' => 0,
        ];
    }
}
