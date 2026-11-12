<?php

namespace Database\Factories;

use App\Models\TripType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TripType>
 */
class TripTypeFactory extends Factory
{
    /**
     * Define the model's default state: an active trip type with a unique name.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['1/2 Day', '3/4 Day', 'Full Day', 'Overnight']).' '.fake()->unique()->numerify('Run ###');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
