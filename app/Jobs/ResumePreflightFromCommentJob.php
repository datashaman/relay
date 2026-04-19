<?php

namespace App\Jobs;

use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Models\Run;
use App\Models\Stage;
use App\Services\OrchestratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stuffs an external comment body into clarification_answers so the existing
 * PreflightAgent::execute() round-cap branch fires, then resumes the stage.
 *
 * The sentinel key `__comment__` is recognised by the assess-prompt builder
 * and rendered as a "## Reply from issue comment" section rather than the
 * id-keyed "## Clarification Answers" section.
 */
class ResumePreflightFromCommentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const COMMENT_KEY = '__comment__';

    public function __construct(
        public Run $run,
        public string $commentBody,
        public ?string $authorIdentifier = null,
    ) {}

    public function handle(OrchestratorService $orchestrator): void
    {
        $run = $this->run->fresh();

        if (! $run) {
            return;
        }

        $stage = $run->stages()
            ->where('name', StageName::Preflight)
            ->where('status', StageStatus::AwaitingApproval)
            ->latest('id')
            ->first();

        if (! $stage instanceof Stage) {
            // The Run advanced past clarification (or completed) between webhook
            // ack and job execution. Drop silently.
            return;
        }

        $answers = $run->clarification_answers ?? [];
        $answers[self::COMMENT_KEY] = $this->commentBody;

        $run->update(['clarification_answers' => $answers]);

        $orchestrator->resume($stage);
    }
}
