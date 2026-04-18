<?php

namespace Database\Factories;

use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

class RepositoryFactory extends Factory
{
    protected $model = Repository::class;

    public function definition(): array
    {
        return [
            'name' => fake()->slug(2),
            'path' => '/tmp/repos/'.fake()->slug(2),
            'default_branch' => 'main',
            'worktree_root' => '/tmp/worktrees',
            'setup_script' => null,
            'teardown_script' => null,
            'run_script' => null,
            'framework' => null,
            'framework_source' => null,
        ];
    }
}
