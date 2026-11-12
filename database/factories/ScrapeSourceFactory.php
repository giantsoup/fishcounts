<?php

namespace Database\Factories;

use App\Enums\ParserEngine;
use App\Enums\SourceType;
use App\Models\ScrapeSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScrapeSource>
 */
class ScrapeSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Landing';

        return [
            'name' => $name,
            'slug' => Str::slug($name, '_'),
            'source_type' => SourceType::Landing,
            'base_url' => fake()->url(),
            'parser_engine' => ParserEngine::Deterministic,
        ];
    }
}
