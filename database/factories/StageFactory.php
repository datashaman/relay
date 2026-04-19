<?php

namespace Database\Factories;

use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Models\Run;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stage>
 */
class StageFactory extends Factory
{
    protected $model = Stage::class;

    public function definition(): array
    {
        return [
            'run_id' => Run::factory(),
            'name' => fake()->randomElement(StageName::cases()),
            'status' => StageStatus::Pending,
            'iteration' => 1,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
