<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IngestionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestionEvent>
 */
class IngestionEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_system'    => 'intake',
            'source_record_id' => fake()->unique()->regexify('rec[A-Za-z0-9]{14}'),
            'content_hash'     => hash('sha256', fake()->unique()->sentence()),
            'document_id'      => null,
            'status'           => 'queued',
            'attempts'         => 0,
            'error'            => null,
        ];
    }
}
