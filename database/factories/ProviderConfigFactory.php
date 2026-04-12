<?php

namespace Database\Factories;

use App\Models\ProviderConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProviderConfigFactory extends Factory
{
    protected $model = ProviderConfig::class;

    public function definition(): array
    {
        return [
            'provider' => fake()->randomElement(['anthropic', 'openai', 'gemini', 'claude_code_cli']),
            'scope' => 'global',
            'scope_id' => null,
            'stage' => null,
            'settings' => ['model' => 'claude-sonnet-4-6'],
        ];
    }
}
