<?php

namespace App\Services;

use App\Enums\AutonomyLevel;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Events\StageTransitioned;
use App\Jobs\ExecuteStageJob;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Stage;
use App\Models\StageEvent;

class OrchestratorService
{
    private const STAGE_ORDER = [
        StageName::Preflight,
        StageName::Implement,
        StageName::Verify,
        StageName::Release,
    ];

    public function __construct(
        private EscalationRuleService $escalationRuleService,
    ) {}

    public function startRun(Issue $issue, array $context = []): Run
    {
        $run = Run::create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
            'iteration' => 0,
            'started_at' => now(),
        ]);

        $issue->update(['status' => IssueStatus::InProgress]);

        $firstStage = $this->createStage($run, StageName::Preflight);

        $this->transitionStage($firstStage, $context);

        return $run;
    }

    public function startStage(Stage $stage, array $context = []): void
    {
        $this->transitionStage($stage, $context);
    }

    public function pause(Stage $stage): void
    {
        $stage->update(['status' => StageStatus::AwaitingApproval]);
        $this->recordEvent($stage, 'paused', 'system');
        $this->broadcastTransition($stage);
    }

    public function resume(Stage $stage, array $context = []): void
    {
        $stage->update([
            'status' => StageStatus::Running,
            'started_at' => $stage->started_at ?? now(),
        ]);

        $this->recordEvent($stage, 'resumed', 'user');
        $this->broadcastTransition($stage);

        ExecuteStageJob::dispatch($stage, $context);
    }

    public function bounce(Stage $stage, array $failureReport = []): void
    {
        $stage->update([
            'status' => StageStatus::Bounced,
            'completed_at' => now(),
        ]);

        $this->recordEvent($stage, 'bounced', 'system', [
            'failure_report' => $failureReport,
        ]);
        $this->broadcastTransition($stage);

        $run = $stage->run;
        $previousName = $this->previousStageName($stage->name);

        if (! $previousName) {
            $this->failStage($stage);

            return;
        }

        $run->increment('iteration');

        $newStage = $this->createStage($run, $previousName, $run->iteration);
        $this->recordEvent($newStage, 'bounce_received', 'system', [
            'from_stage' => $stage->name->value,
            'failure_report' => $failureReport,
            'iteration' => $run->iteration,
        ]);

        $this->transitionStage($newStage, ['failure_report' => $failureReport]);
    }

    public function complete(Stage $stage, array $context = []): void
    {
        $stage->update([
            'status' => StageStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->recordEvent($stage, 'completed', 'system');
        $this->broadcastTransition($stage);

        $nextName = $this->nextStageName($stage->name);

        if (! $nextName) {
            $this->completeRun($stage->run);

            return;
        }

        $run = $stage->run;
        $nextStage = $this->createStage($run, $nextName);
        $this->transitionStage($nextStage, $context);
    }

    public function fail(Stage $stage, ?string $reason = null): void
    {
        $this->failStage($stage, $reason);
    }

    private function transitionStage(Stage $stage, array $context = []): void
    {
        $run = $stage->run;
        $issue = $run->issue;

        $effectiveLevel = $this->escalationRuleService->resolveWithEscalation(
            $issue,
            $stage->name,
            $context,
            $stage,
        );

        if ($this->requiresApproval($effectiveLevel)) {
            $stage->update(['status' => StageStatus::AwaitingApproval]);
            $this->recordEvent($stage, 'awaiting_approval', 'system', [
                'autonomy_level' => $effectiveLevel->value,
            ]);
            $this->broadcastTransition($stage);

            return;
        }

        $stage->update([
            'status' => StageStatus::Running,
            'started_at' => now(),
        ]);

        $this->recordEvent($stage, 'started', 'system', [
            'autonomy_level' => $effectiveLevel->value,
        ]);
        $this->broadcastTransition($stage);

        ExecuteStageJob::dispatch($stage, $context);
    }

    private function requiresApproval(AutonomyLevel $level): bool
    {
        return match ($level) {
            AutonomyLevel::Manual, AutonomyLevel::Supervised => true,
            AutonomyLevel::Assisted, AutonomyLevel::Autonomous => false,
        };
    }

    private function failStage(Stage $stage, ?string $reason = null): void
    {
        $stage->update([
            'status' => StageStatus::Failed,
            'completed_at' => now(),
        ]);

        $this->recordEvent($stage, 'failed', 'system', array_filter([
            'reason' => $reason,
        ]));
        $this->broadcastTransition($stage);

        $run = $stage->run;
        $run->update([
            'status' => RunStatus::Failed,
            'completed_at' => now(),
        ]);

        $run->issue->update(['status' => IssueStatus::Failed]);
    }

    private function completeRun(Run $run): void
    {
        $run->update([
            'status' => RunStatus::Completed,
            'completed_at' => now(),
        ]);

        $run->issue->update(['status' => IssueStatus::Completed]);
    }

    private function createStage(Run $run, StageName $name, int $iteration = 1): Stage
    {
        return Stage::create([
            'run_id' => $run->id,
            'name' => $name,
            'status' => StageStatus::Pending,
            'iteration' => $iteration,
        ]);
    }

    private function nextStageName(StageName $current): ?StageName
    {
        $index = array_search($current, self::STAGE_ORDER);

        return self::STAGE_ORDER[$index + 1] ?? null;
    }

    private function previousStageName(StageName $current): ?StageName
    {
        $index = array_search($current, self::STAGE_ORDER);

        return $index > 0 ? self::STAGE_ORDER[$index - 1] : null;
    }

    private function recordEvent(Stage $stage, string $type, string $actor, array $payload = []): void
    {
        StageEvent::create([
            'stage_id' => $stage->id,
            'type' => $type,
            'actor' => $actor,
            'payload' => ! empty($payload) ? $payload : null,
        ]);
    }

    private function broadcastTransition(Stage $stage): void
    {
        StageTransitioned::dispatch($stage->fresh());
    }
}
