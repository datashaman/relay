<?php

namespace App\Jobs;

use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Models\Run;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Services\ImplementAgent;
use App\Services\OrchestratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;

/**
 * Dispatched when a user clicks the "Resolve" button on a run that has
 * detected merge conflicts. Starts the merge, hands off to the Implement
 * agent to resolve the markers, then commits + pushes the merge commit.
 *
 * Reuses {@see ImplementAgent} (the only AI-driven code-editing agent we
 * have) rather than introducing a separate resolution engine.
 */
class ResolveConflictsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Run $run,
    ) {}

    public function handle(ImplementAgent $implement, OrchestratorService $orchestrator): void
    {
        $run = $this->run->fresh(['issue.repository', 'repository', 'stages']);

        if (! $run || ! $run->has_conflicts) {
            return;
        }

        $repository = $run->repository ?? $run->issue?->repository;
        $worktreePath = $run->worktree_path;

        if (! $repository || ! $worktreePath) {
            return;
        }

        $stage = $this->createResolutionStage($run);

        $this->recordEvent($stage, 'conflict_resolution_started', 'system', [
            'files' => $run->conflict_files ?? [],
        ]);

        try {
            // Clear stale merge state from the last detection probe.
            $this->abortInProgressMerge($worktreePath);

            $targetBranch = $repository->default_branch ?: 'main';

            $fetch = Process::path($worktreePath)
                ->timeout(60)
                ->run(['git', 'fetch', 'origin', $targetBranch]);
            if (! $fetch->successful()) {
                $this->fail($stage, $run, 'Failed to fetch target branch: '.trim($fetch->errorOutput()));

                return;
            }

            // Start the merge we expect to conflict.
            $merge = Process::path($worktreePath)
                ->timeout(60)
                ->run(['git', 'merge', '--no-commit', '--no-ff', 'origin/'.$targetBranch]);

            $conflictFiles = $this->listConflictFiles($worktreePath);

            if ($merge->successful() && empty($conflictFiles)) {
                // Merge succeeded cleanly — commit what we have and call it done.
                $this->commitMerge($worktreePath, $targetBranch);
                $this->pushBranch($worktreePath, $run->branch);
                $this->markResolved($run, $stage, $conflictFiles, $orchestrator);

                return;
            }

            $run->update(['conflict_files' => $conflictFiles]);

            // Hand off to the Implement agent with a synthetic preflight doc.
            // We mutate the in-memory Run, then attach it back to the stage so
            // ImplementAgent (which does `$stage->run`) doesn't lazy-load a
            // fresh copy from the DB and discard our synthetic doc.
            $originalDoc = $run->preflight_doc;
            $run->preflight_doc = $this->buildConflictDoc($targetBranch, $conflictFiles);
            $stage->setRelation('run', $run);
            $implement->execute($stage, ['resolving_conflicts' => true]);
            $run->preflight_doc = $originalDoc;

            // Verify there are no remaining conflict markers.
            $remaining = $this->listConflictFiles($worktreePath);
            if (! empty($remaining)) {
                $this->abortInProgressMerge($worktreePath);
                $this->fail($stage, $run, 'AI left unresolved conflicts: '.implode(', ', $remaining));

                return;
            }

            $this->commitMerge($worktreePath, $targetBranch);
            $this->pushBranch($worktreePath, $run->branch);
            $this->markResolved($run, $stage, $conflictFiles, $orchestrator);
        } catch (\Throwable $e) {
            $this->abortInProgressMerge($worktreePath);
            $this->fail($stage, $run, 'Conflict resolution threw: '.$e->getMessage());
        }
    }

    private function createResolutionStage(Run $run): Stage
    {
        $latest = $run->stages()->latest('id')->first();

        return Stage::create([
            'run_id' => $run->id,
            'name' => $latest?->name ?? StageName::Implement,
            'status' => StageStatus::Running,
            'iteration' => $latest?->iteration ?? 1,
            'started_at' => now(),
        ]);
    }

    private function buildConflictDoc(string $targetBranch, array $files): string
    {
        $list = empty($files) ? '(no files listed)' : '- '.implode("\n- ", $files);

        return <<<DOC
# Resolve Merge Conflicts

You are in the middle of a merge of `origin/{$targetBranch}` into the current branch.
The following files contain conflict markers (`<<<<<<<`, `=======`, `>>>>>>>`) that
need to be resolved:

{$list}

## Instructions

- For each conflicted file, read the file, understand both sides of the conflict, and
  produce a merged version that preserves the intent of the original change on this
  branch while incorporating any compatible changes from `{$targetBranch}`.
- Remove all conflict markers.
- Do NOT commit, push, or create a PR — that will happen automatically after you finish.
- When done, call `implementation_complete` with a summary of how you resolved each
  conflict.
DOC;
    }

    private function listConflictFiles(string $worktreePath): array
    {
        $result = Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'diff', '--name-only', '--diff-filter=U']);

        if (! $result->successful()) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $result->output())),
            fn ($line) => $line !== '',
        ));
    }

    private function abortInProgressMerge(string $worktreePath): void
    {
        $check = Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'rev-parse', '-q', '--verify', 'MERGE_HEAD']);

        if (! $check->successful()) {
            return;
        }

        Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'merge', '--abort']);
    }

    private function commitMerge(string $worktreePath, string $targetBranch): void
    {
        Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'add', '-A'])
            ->throw();

        Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'commit', '-m', "Merge origin/{$targetBranch} (conflicts resolved by Relay)"])
            ->throw();
    }

    private function pushBranch(string $worktreePath, ?string $branch): void
    {
        if (! $branch) {
            return;
        }

        Process::path($worktreePath)
            ->timeout(60)
            ->run(['git', 'push', 'origin', $branch])
            ->throw();
    }

    private function markResolved(Run $run, Stage $stage, array $files, OrchestratorService $orchestrator): void
    {
        $run->update([
            'has_conflicts' => false,
            'conflict_detected_at' => null,
            'conflict_files' => null,
        ]);

        $stage->update([
            'status' => StageStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->recordEvent($stage, 'conflict_resolved', 'system', [
            'files' => $files,
        ]);

        // Hand back to the orchestrator so the run continues normally —
        // this clears any Stuck state and dispatches the next stage.
        $orchestrator->restart($run->fresh());
    }

    private function fail(Stage $stage, Run $run, string $reason): void
    {
        $stage->update([
            'status' => StageStatus::Failed,
            'completed_at' => now(),
        ]);

        $this->recordEvent($stage, 'conflict_resolution_failed', 'system', [
            'reason' => $reason,
        ]);
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
}
