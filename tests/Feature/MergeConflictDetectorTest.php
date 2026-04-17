<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Enums\StageStatus;
use App\Models\Issue;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Services\MergeConflictDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class MergeConflictDetectorTest extends TestCase
{
    use RefreshDatabase;

    private MergeConflictDetector $detector;

    private Repository $repository;

    private Run $run;

    private Stage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new MergeConflictDetector();

        $this->repository = Repository::factory()->create([
            'path' => '/repos/my-project',
            'default_branch' => 'main',
            'worktree_root' => '/worktrees',
        ]);

        $issue = Issue::factory()->create(['repository_id' => $this->repository->id]);

        $this->run = Run::factory()->create([
            'issue_id' => $issue->id,
            'repository_id' => $this->repository->id,
            'status' => RunStatus::Running,
            'branch' => 'relay/fix-123',
            'worktree_path' => '/worktrees/relay-1',
        ]);

        $this->stage = Stage::factory()->create([
            'run_id' => $this->run->id,
            'status' => StageStatus::Pending,
        ]);
    }

    public function test_returns_clean_when_merge_succeeds(): void
    {
        Process::fake([
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $result = $this->detector->probe($this->run->fresh(['issue.repository', 'repository', 'stages']));

        $this->assertEquals(MergeConflictDetector::OUTCOME_CLEAN, $result['outcome']);
        $this->assertFalse((bool) $this->run->fresh()->has_conflicts);
    }

    public function test_returns_conflict_and_records_files_when_merge_fails_with_unmerged_paths(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (($process->command[1] ?? null) === 'merge' && in_array('--no-commit', $process->command, true)) {
                return Process::result(output: '', errorOutput: 'CONFLICT', exitCode: 1);
            }
            if (($process->command[1] ?? null) === 'diff' && in_array('--diff-filter=U', $process->command, true)) {
                return Process::result(output: "src/foo.php\nsrc/bar.php\n", exitCode: 0);
            }
            if (($process->command[1] ?? null) === 'rev-parse' && in_array('MERGE_HEAD', $process->command, true)) {
                return Process::result(output: 'abc123', exitCode: 0);
            }
            return Process::result(output: '', exitCode: 0);
        });

        $result = $this->detector->probe($this->run->fresh(['issue.repository', 'repository', 'stages']));

        $this->assertEquals(MergeConflictDetector::OUTCOME_CONFLICT, $result['outcome']);
        $this->assertEquals(['src/foo.php', 'src/bar.php'], $result['files']);

        $run = $this->run->fresh();
        $this->assertTrue($run->has_conflicts);
        $this->assertEquals(['src/foo.php', 'src/bar.php'], $run->conflict_files);
        $this->assertNotNull($run->conflict_detected_at);
    }

    public function test_always_aborts_merge_after_probe(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (($process->command[1] ?? null) === 'rev-parse' && in_array('MERGE_HEAD', $process->command, true)) {
                return Process::result(output: 'abc123', exitCode: 0);
            }
            return Process::result(output: '', exitCode: 0);
        });

        $this->detector->probe($this->run->fresh(['issue.repository', 'repository', 'stages']));

        Process::assertRan(function (PendingProcess $process) {
            return $process->command === ['git', 'merge', '--abort'];
        });
    }

    public function test_does_not_call_abort_when_no_merge_in_progress(): void
    {
        // rev-parse MERGE_HEAD fails → no merge to abort.
        Process::fake(function (PendingProcess $process) {
            if (($process->command[1] ?? null) === 'rev-parse' && in_array('MERGE_HEAD', $process->command, true)) {
                return Process::result(output: '', exitCode: 1);
            }
            return Process::result(output: '', exitCode: 0);
        });

        $this->detector->probe($this->run->fresh(['issue.repository', 'repository', 'stages']));

        Process::assertNotRan(function (PendingProcess $process) {
            return $process->command === ['git', 'merge', '--abort'];
        });
    }

    public function test_skips_when_no_worktree_path(): void
    {
        $this->run->update(['worktree_path' => null]);

        Process::fake();

        $result = $this->detector->probe($this->run->fresh());

        $this->assertEquals(MergeConflictDetector::OUTCOME_SKIPPED, $result['outcome']);
        $this->assertEquals('no_worktree', $result['reason']);
        Process::assertNothingRan();
    }

    public function test_skips_when_run_is_completed(): void
    {
        $this->run->update(['status' => RunStatus::Completed]);

        Process::fake();

        $result = $this->detector->probe($this->run->fresh());

        $this->assertEquals(MergeConflictDetector::OUTCOME_SKIPPED, $result['outcome']);
        Process::assertNothingRan();
    }

    public function test_skips_when_stage_is_running(): void
    {
        $this->stage->update(['status' => StageStatus::Running]);

        Process::fake();

        $result = $this->detector->probe($this->run->fresh(['stages']));

        $this->assertEquals(MergeConflictDetector::OUTCOME_SKIPPED, $result['outcome']);
        $this->assertEquals('stage_running', $result['reason']);
        Process::assertNothingRan();
    }

    public function test_returns_error_when_fetch_fails(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (($process->command[1] ?? null) === 'fetch') {
                return Process::result(output: '', errorOutput: 'network unreachable', exitCode: 1);
            }
            return Process::result(output: '', exitCode: 0);
        });

        $result = $this->detector->probe($this->run->fresh(['issue.repository', 'repository', 'stages']));

        $this->assertEquals(MergeConflictDetector::OUTCOME_ERROR, $result['outcome']);
        $this->assertStringContainsString('fetch_failed', $result['reason']);
        $this->assertFalse((bool) $this->run->fresh()->has_conflicts);
    }

    public function test_clears_conflict_flags_when_a_previous_conflict_is_now_clean(): void
    {
        $this->run->update([
            'has_conflicts' => true,
            'conflict_detected_at' => now(),
            'conflict_files' => ['src/foo.php'],
        ]);

        Process::fake([
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $result = $this->detector->probe($this->run->fresh(['issue.repository', 'repository', 'stages']));

        $this->assertEquals(MergeConflictDetector::OUTCOME_CLEAN, $result['outcome']);

        $run = $this->run->fresh();
        $this->assertFalse($run->has_conflicts);
        $this->assertNull($run->conflict_detected_at);
        $this->assertNull($run->conflict_files);
    }

    public function test_records_stage_event_on_new_conflict(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (($process->command[1] ?? null) === 'merge' && in_array('--no-commit', $process->command, true)) {
                return Process::result(output: '', exitCode: 1);
            }
            if (($process->command[1] ?? null) === 'diff' && in_array('--diff-filter=U', $process->command, true)) {
                return Process::result(output: "a.php\n", exitCode: 0);
            }
            if (($process->command[1] ?? null) === 'rev-parse' && in_array('MERGE_HEAD', $process->command, true)) {
                return Process::result(output: 'abc', exitCode: 0);
            }
            return Process::result(output: '', exitCode: 0);
        });

        $this->detector->probe($this->run->fresh(['issue.repository', 'repository', 'stages']));

        $event = StageEvent::where('stage_id', $this->stage->id)
            ->where('type', 'conflict_detected')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals(['a.php'], $event->payload['files']);
    }

    public function test_does_not_spam_events_on_repeated_detection(): void
    {
        $this->run->update([
            'has_conflicts' => true,
            'conflict_detected_at' => now(),
            'conflict_files' => ['a.php'],
        ]);

        Process::fake(function (PendingProcess $process) {
            if (($process->command[1] ?? null) === 'merge' && in_array('--no-commit', $process->command, true)) {
                return Process::result(output: '', exitCode: 1);
            }
            if (($process->command[1] ?? null) === 'diff' && in_array('--diff-filter=U', $process->command, true)) {
                return Process::result(output: "a.php\n", exitCode: 0);
            }
            if (($process->command[1] ?? null) === 'rev-parse' && in_array('MERGE_HEAD', $process->command, true)) {
                return Process::result(output: 'abc', exitCode: 0);
            }
            return Process::result(output: '', exitCode: 0);
        });

        $this->detector->probe($this->run->fresh(['issue.repository', 'repository', 'stages']));

        $this->assertEquals(0, StageEvent::where('type', 'conflict_detected')->count());
    }

    public function test_probe_all_active_skips_runs_without_worktree(): void
    {
        Run::factory()->create([
            'status' => RunStatus::Running,
            'worktree_path' => null,
            'branch' => 'relay/other',
        ]);

        Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

        $results = $this->detector->probeAllActive();

        // Only the setUp run has a worktree path.
        $this->assertCount(1, $results);
        $this->assertEquals($this->run->id, $results[0]['run_id']);
    }
}
