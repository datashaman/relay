<?php

namespace App\Services;

use App\Enums\StageStatus;
use App\Models\Run;
use App\Models\StageEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class MergeConflictDetector
{
    /**
     * Outcome strings returned by {@see probe()}.
     */
    public const OUTCOME_CLEAN = 'clean';

    public const OUTCOME_CONFLICT = 'conflict';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_ERROR = 'error';

    private const SHELL_TIMEOUT = 60;

    /**
     * Attempt a dry-run merge of the run's target branch into its
     * branch to detect conflicts. Always aborts the merge afterwards
     * so the working tree is left clean.
     *
     * @return array{outcome: string, files?: array<string>, reason?: string}
     */
    public function probe(Run $run): array
    {
        if (! $this->isProbeable($run)) {
            return ['outcome' => self::OUTCOME_SKIPPED, 'reason' => 'run_not_probeable'];
        }

        $repository = $run->repository ?? $run->issue?->repository;
        if (! $repository) {
            return ['outcome' => self::OUTCOME_SKIPPED, 'reason' => 'no_repository'];
        }

        $worktreePath = $run->worktree_path;
        if (! $worktreePath) {
            return ['outcome' => self::OUTCOME_SKIPPED, 'reason' => 'no_worktree'];
        }

        // Guard against probing while a stage is actively mutating the worktree.
        $activeStage = $run->stages()
            ->where('status', StageStatus::Running)
            ->exists();
        if ($activeStage) {
            return ['outcome' => self::OUTCOME_SKIPPED, 'reason' => 'stage_running'];
        }

        $targetBranch = $repository->default_branch ?: 'main';

        // Clear any stale merge state left behind by a previous probe or run.
        $this->abortInProgressMerge($worktreePath);

        // Fetch latest target — non-fatal on failure (network/auth issues).
        $fetchResult = Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['git', 'fetch', 'origin', $targetBranch]);

        if (! $fetchResult->successful()) {
            return [
                'outcome' => self::OUTCOME_ERROR,
                'reason' => 'fetch_failed: '.trim($fetchResult->errorOutput()),
            ];
        }

        try {
            $mergeResult = Process::path($worktreePath)
                ->timeout(self::SHELL_TIMEOUT)
                ->run(['git', 'merge', '--no-commit', '--no-ff', 'origin/'.$targetBranch]);

            if ($mergeResult->successful()) {
                $this->clearConflicts($run);

                return ['outcome' => self::OUTCOME_CLEAN];
            }

            $files = $this->listConflictFiles($worktreePath);

            if (empty($files)) {
                // Merge failed but no conflict files — treat as error (e.g. branch missing).
                return [
                    'outcome' => self::OUTCOME_ERROR,
                    'reason' => 'merge_failed: '.trim($mergeResult->errorOutput()),
                ];
            }

            $this->recordConflicts($run, $files);

            return ['outcome' => self::OUTCOME_CONFLICT, 'files' => $files];
        } finally {
            $this->abortInProgressMerge($worktreePath);
        }
    }

    /**
     * Run detection across every active run and record stage events.
     *
     * @return array<int, array{run_id: int, outcome: string}>
     */
    public function probeAllActive(): array
    {
        $results = [];

        Run::query()
            ->active()
            ->whereNotNull('worktree_path')
            ->whereNotNull('branch')
            ->with(['issue.repository', 'repository', 'stages'])
            ->get()
            ->each(function (Run $run) use (&$results) {
                try {
                    $result = $this->probe($run);
                } catch (\Throwable $e) {
                    Log::warning('MergeConflictDetector probe failed', [
                        'run_id' => $run->id,
                        'error' => $e->getMessage(),
                    ]);
                    $result = ['outcome' => self::OUTCOME_ERROR, 'reason' => $e->getMessage()];
                }

                $results[] = ['run_id' => $run->id, 'outcome' => $result['outcome']];
            });

        return $results;
    }

    private function isProbeable(Run $run): bool
    {
        if ($run->status === null) {
            return false;
        }

        $status = $run->status->value;

        return in_array($status, ['pending', 'running', 'stuck'], true);
    }

    private function listConflictFiles(string $worktreePath): array
    {
        $result = Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['git', 'diff', '--name-only', '--diff-filter=U']);

        if (! $result->successful()) {
            return [];
        }

        $files = array_values(array_filter(
            array_map('trim', explode("\n", $result->output())),
            fn ($line) => $line !== '',
        ));

        return $files;
    }

    private function abortInProgressMerge(string $worktreePath): void
    {
        // git merge --abort errors when no merge is in progress, so guard with MERGE_HEAD check.
        $check = Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['git', 'rev-parse', '-q', '--verify', 'MERGE_HEAD']);

        if (! $check->successful()) {
            return;
        }

        Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['git', 'merge', '--abort']);
    }

    private function recordConflicts(Run $run, array $files): void
    {
        $wasConflicted = (bool) $run->has_conflicts;

        $run->update([
            'has_conflicts' => true,
            'conflict_detected_at' => now(),
            'conflict_files' => $files,
        ]);

        // Avoid spamming events — only log when the conflict state first appears.
        if (! $wasConflicted) {
            $this->recordEvent($run, 'conflict_detected', [
                'files' => $files,
            ]);
        }
    }

    private function clearConflicts(Run $run): void
    {
        if (! $run->has_conflicts) {
            return;
        }

        $run->update([
            'has_conflicts' => false,
            'conflict_detected_at' => null,
            'conflict_files' => null,
        ]);

        $this->recordEvent($run, 'conflict_cleared', []);
    }

    private function recordEvent(Run $run, string $type, array $payload): void
    {
        $stage = $run->stages()->latest('id')->first();
        if (! $stage) {
            return;
        }

        StageEvent::create([
            'stage_id' => $stage->id,
            'type' => $type,
            'actor' => 'system',
            'payload' => ! empty($payload) ? $payload : null,
        ]);
    }
}
