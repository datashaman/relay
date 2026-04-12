<?php

namespace Database\Factories;

use App\Models\OauthToken;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

class OauthTokenFactory extends Factory
{
    protected $model = OauthToken::class;

    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'provider' => fake()->randomElement(['github', 'jira']),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->optional()->sha256(),
            'expires_at' => fake()->optional()->dateTimeBetween('now', '+1 year'),
            'scopes' => ['repo', 'read:org'],
        ];
    }
}
