<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Models\Issue;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

class RunFactory extends Factory
{
    protected $model = Run::class;

    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'status' => RunStatus::Pending,
            'stuck_state' => null,
            'branch' => 'relay/' . fake()->slug(3),
            'worktree_path' => null,
            'preflight_doc' => null,
            'preflight_doc_history' => null,
            'known_facts' => null,
            'clarification_questions' => null,
            'clarification_answers' => null,
            'iteration' => 0,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
