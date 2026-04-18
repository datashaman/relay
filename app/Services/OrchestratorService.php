<?php

namespace App\Services;

use App\Enums\AutonomyLevel;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Enums\StuckState;
use App\Events\RunStuck;
use App\Events\StageTransitioned;
use App\Jobs\ExecuteStageJob;
use App\Models\Issue;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Support\Logging\PipelineLogger;
use Illuminate\Support\Carbon;

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
        private WorktreeService $worktreeService,
    ) {}

    public function startRun(Issue $issue, ?Repository $repository = null, array $context = []): Run
    {
        $repository ??= $issue->repository;

        $run = Run::create([
            'issue_id' => $issue->id,
            'repository_id' => $repository?->id,
            'status' => RunStatus::Running,
            'iteration' => 0,
            'started_at' => now(),
        ]);

        $issue->update(['status' => IssueStatus::InProgress]);

        if (! $repository) {
            $this->failRunImmediately($run, $issue, 'Issue is not linked to a repository. The sync should attach one; check your source config.');

            return $run;
        }

        try {
            $this->worktreeService->createWorktree($run, $repository);
        } catch (\Throwable $e) {
            $this->failRunImmediately($run, $issue, 'Worktree setup failed: '.$e->getMessage(), $e);

            return $run;
        }

        PipelineLogger::event($run, 'run_started', [
            'stage' => StageName::Preflight->value,
            'repository_id' => $repository->id,
            'repository' => $repository->name,
        ]);

        $firstStage = $this->createStage($run, StageName::Preflight);

        $this->transitionStage($firstStage, $context);

        return $run;
    }

    private function failRunImmediately(Run $run, Issue $issue, string $reason, ?\Throwable $exception = null): void
    {
        $stage = $this->createStage($run, StageName::Preflight);
        $stage->update(['status' => StageStatus::Failed, 'completed_at' => now()]);
        $this->recordEvent($stage, 'failed', 'system', ['reason' => $reason]);

        $run->update(['status' => RunStatus::Failed, 'completed_at' => now()]);
        $issue->update(['status' => IssueStatus::Failed]);

        PipelineLogger::stageFailed($run, StageName::Preflight->value, $exception, [
            'reason' => $reason,
        ]);
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
        $completedAt = now();
        $durationMs = $this->elapsedMillis($stage, $completedAt);

        $stage->update([
            'status' => StageStatus::Bounced,
            'completed_at' => $completedAt,
        ]);

        $this->recordEvent($stage, 'bounced', 'system', [
            'failure_report' => $failureReport,
        ]);
        $this->broadcastTransition($stage);

        PipelineLogger::event($stage->run, 'stage_bounced', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
            'duration_ms' => $durationMs,
            'failure_count' => count($failureReport),
        ]);

        $run = $stage->run;
        $previousName = $this->previousStageName($stage->name);

        if (! $previousName) {
            $this->failStage($stage);

            return;
        }

        $run->increment('iteration');
        $run->refresh();

        $iterationCap = config('relay.iteration_cap', 5);

        if ($run->iteration >= $iterationCap) {
            $this->markStuck($stage, StuckState::IterationCap, [
                'iteration' => $run->iteration,
                'cap' => $iterationCap,
                'failure_report' => $failureReport,
            ]);

            return;
        }

        $newStage = $this->createStage($run, $previousName, $run->iteration);
        $this->recordEvent($newStage, "implement.iteration.{$run->iteration}", 'system', [
            'from_stage' => $stage->name->value,
            'failure_report' => $failureReport,
            'iteration' => $run->iteration,
        ]);

        $this->transitionStage($newStage, ['failure_report' => $failureReport]);
    }

    public function complete(Stage $stage, array $context = []): void
    {
        $completedAt = now();
        $durationMs = $this->elapsedMillis($stage, $completedAt);

        $stage->update([
            'status' => StageStatus::Completed,
            'completed_at' => $completedAt,
        ]);

        $this->recordEvent($stage, 'completed', 'system');
        $this->broadcastTransition($stage);

        PipelineLogger::stageCompleted($stage->run, $stage->name->value, $durationMs, [
            'iteration' => $stage->iteration,
        ]);

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

    public function markStuck(Stage $stage, StuckState $stuckState, array $context = []): void
    {
        $stage->update([
            'status' => StageStatus::Stuck,
            'completed_at' => now(),
        ]);

        $run = $stage->run;
        $run->update([
            'status' => RunStatus::Stuck,
            'stuck_state' => $stuckState,
            'stuck_unread' => true,
        ]);

        $run->issue->update(['status' => IssueStatus::Stuck]);

        $this->recordEvent($stage, 'stuck', 'system', array_merge(
            ['stuck_state' => $stuckState->value],
            $context,
        ));
        $this->broadcastTransition($stage);

        PipelineLogger::stageFailed($run, $stage->name->value, null, [
            'iteration' => $stage->iteration,
            'reason' => 'stuck',
            'stuck_state' => $stuckState->value,
        ]);

        RunStuck::dispatch($run->fresh());
    }

    public function giveGuidance(Run $run, string $guidance): void
    {
        $run->update([
            'guidance' => $guidance,
            'status' => RunStatus::Running,
            'stuck_state' => null,
            'stuck_unread' => false,
        ]);

        $run->issue->update(['status' => IssueStatus::InProgress]);

        $latestStage = $run->stages()->latest('id')->first();
        $newStage = $this->createStage($run, $latestStage->name, $latestStage->iteration);

        $this->recordEvent($newStage, 'guidance_received', 'user', [
            'guidance' => $guidance,
        ]);

        $this->transitionStage($newStage, ['guidance' => $guidance]);
    }

    public function retryStage(Stage $stage): void
    {
        $run = $stage->run;

        $run->update([
            'status' => RunStatus::Running,
            'completed_at' => null,
        ]);

        $run->issue->update(['status' => IssueStatus::InProgress]);

        $newStage = $this->createStage($run, $stage->name, $stage->iteration);
        $this->recordEvent($newStage, 'retried', 'user');

        $this->transitionStage($newStage);
    }

    public function restart(Run $run): void
    {
        $run->update([
            'status' => RunStatus::Running,
            'stuck_state' => null,
            'stuck_unread' => false,
        ]);

        $run->issue->update(['status' => IssueStatus::InProgress]);

        $latestStage = $run->stages()->latest('id')->first();
        $newStage = $this->createStage($run, $latestStage->name, $latestStage->iteration);

        $this->recordEvent($newStage, 'restarted', 'user');

        $this->transitionStage($newStage);
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

            PipelineLogger::event($run, 'stage_awaiting_approval', [
                'stage' => $stage->name->value,
                'iteration' => $stage->iteration,
                'autonomy_level' => $effectiveLevel->value,
            ]);

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

        PipelineLogger::stageStarted($run, $stage->name->value, [
            'iteration' => $stage->iteration,
            'autonomy_level' => $effectiveLevel->value,
            'repository_id' => $run->repository_id,
        ]);

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
        $completedAt = now();
        $durationMs = $this->elapsedMillis($stage, $completedAt);

        $stage->update([
            'status' => StageStatus::Failed,
            'completed_at' => $completedAt,
        ]);

        $this->recordEvent($stage, 'failed', 'system', array_filter([
            'reason' => $reason,
        ]));
        $this->broadcastTransition($stage);

        $run = $stage->run;
        $run->update([
            'status' => RunStatus::Failed,
            'completed_at' => $completedAt,
        ]);

        $run->issue->update(['status' => IssueStatus::Failed]);

        PipelineLogger::stageFailed($run, $stage->name->value, null, array_filter([
            'iteration' => $stage->iteration,
            'duration_ms' => $durationMs,
            'reason' => $reason,
        ], static fn ($value) => $value !== null));
    }

    private function completeRun(Run $run): void
    {
        $completedAt = now();

        $run->update([
            'status' => RunStatus::Completed,
            'completed_at' => $completedAt,
        ]);

        $run->issue->update(['status' => IssueStatus::Completed]);

        $startedAt = $run->started_at;
        $durationMs = $startedAt ? (int) abs($startedAt->diffInMilliseconds($completedAt)) : 0;

        PipelineLogger::event($run, 'run_completed', [
            'duration_ms' => $durationMs,
            'iteration' => $run->iteration,
        ]);
    }

    private function elapsedMillis(Stage $stage, Carbon $completedAt): int
    {
        $startedAt = $stage->started_at;

        if (! $startedAt) {
            return 0;
        }

        return (int) abs($startedAt->diffInMilliseconds($completedAt));
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
