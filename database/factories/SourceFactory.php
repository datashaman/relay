<?php

namespace Database\Factories;

use App\Enums\SourceType;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

class SourceFactory extends Factory
{
    protected $model = Source::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => fake()->randomElement(SourceType::cases()),
            'external_account' => fake()->userName(),
            'last_synced_at' => fake()->optional()->dateTimeThisMonth(),
            'is_active' => true,
            'config' => null,
        ];
    }
}
