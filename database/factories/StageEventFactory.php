<?php

namespace Database\Factories;

use App\Models\Stage;
use App\Models\StageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class StageEventFactory extends Factory
{
    protected $model = StageEvent::class;

    public function definition(): array
    {
        return [
            'stage_id' => Stage::factory(),
            'type' => fake()->randomElement(['started', 'completed', 'failed', 'bounced', 'approval_requested', 'approved']),
            'actor' => fake()->randomElement(['preflight_agent', 'implement_agent', 'verify_agent', 'release_agent', 'user']),
            'payload' => null,
        ];
    }
}
