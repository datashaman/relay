<?php

namespace Database\Factories;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Repository;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    protected $model = Issue::class;

    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'repository_id' => Repository::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(1, 100000),
            'title' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'status' => IssueStatus::Queued,
            'external_url' => fake()->url(),
            'assignee' => fake()->optional()->userName(),
            'labels' => fake()->optional()->randomElements(['bug', 'feature', 'enhancement', 'docs'], 2),
            'auto_accepted' => false,
            'archived_at' => null,
            'archived_reason' => null,
        ];
    }

    /**
     * Mark the issue as archived with an optional reason.
     */
    public function archived(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
            'archived_reason' => $reason,
        ]);
    }
}
