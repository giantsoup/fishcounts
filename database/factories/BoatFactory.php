<?php

namespace Database\Factories;

use App\Models\Boat;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Boat>
 */
class BoatFactory extends Factory
{
    /**
     * Define the model's default state: an active boat with a unique name.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->lastName().' '.fake()->randomElement(['Queen', 'Star', 'Spirit', 'Dancer']);

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
