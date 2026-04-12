<?php

namespace Database\Factories;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Models\AutonomyConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

class AutonomyConfigFactory extends Factory
{
    protected $model = AutonomyConfig::class;

    public function definition(): array
    {
        return [
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Supervised,
        ];
    }
}
