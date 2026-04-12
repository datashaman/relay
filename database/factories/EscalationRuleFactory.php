<?php

namespace Database\Factories;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Models\EscalationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class EscalationRuleFactory extends Factory
{
    protected $model = EscalationRule::class;

    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'target_level' => AutonomyLevel::Manual,
            'scope' => AutonomyScope::Global,
            'order' => fake()->numberBetween(0, 100),
            'is_enabled' => true,
        ];
    }
}
