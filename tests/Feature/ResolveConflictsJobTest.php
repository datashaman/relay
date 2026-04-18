<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Jobs\ResolveConflictsJob;
use App\Models\Issue;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Stage;
use App\Services\ImplementAgent;
use App\Services\OrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResolveConflictsJobTest extends TestCase
{
    use RefreshDatabase;

    private Run $run;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = Repository::factory()->create([
            'path' => '/repos/my-project',
            'default_branch' => 'main',
            'worktree_root' => '/worktrees',
        ]);

        $issue = Issue::factory()->create([
            'repository_id' => $repository->id,
            'status' => IssueStatus::InProgress,
        ]);

        $this->run = Run::factory()->create([
            'issue_id' => $issue->id,
            'repository_id' => $repository->id,
            'status' => RunStatus::Stuck,
            'branch' => 'relay/fix-123',
            'worktree_path' => '/worktrees/relay-1',
            'has_conflicts' => true,
            'conflict_detected_at' => now(),
            'conflict_files' => ['src/foo.php'],
            'preflight_doc' => '# Original preflight',
        ]);

        Stage::factory()->create([
            'run_id' => $this->run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Stuck,
            'iteration' => 1,
        ]);
    }

    public function test_hands_resolution_doc_to_implement_agent(): void
    {
        Queue::fake();

        Process::fake(function (PendingProcess $process) {
            if (($process->command[1] ?? null) === 'rev-parse' && in_array('MERGE_HEAD', $process->command, true)) {
                // Claim a merge is in progress so --abort is called safely.
                return Process::result(output: 'abc', exitCode: 0);
            }
            if (($process->command[1] ?? null) === 'merge' && in_array('--no-commit', $process->command, true)) {
                // Real merge starts and conflicts.
                return Process::result(output: '', errorOutput: 'CONFLICT', exitCode: 1);
            }
            if (($process->command[1] ?? null) === 'diff' && in_array('--diff-filter=U', $process->command, true)) {
                // Return conflicted files on first call, none after agent resolves them.
                static $call = 0;
                $call++;

                return $call === 1
                    ? Process::result(output: "src/foo.php\n", exitCode: 0)
                    : Process::result(output: '', exitCode: 0);
            }

            return Process::result(output: '', exitCode: 0);
        });

        $capturedDoc = null;

        $implement = $this->mock(ImplementAgent::class, function ($mock) use (&$capturedDoc) {
            $mock->shouldReceive('execute')
                ->once()
                ->andReturnUsing(function (Stage $stage, array $context) use (&$capturedDoc) {
                    $capturedDoc = $stage->run->preflight_doc;
                });
        });

        $this->mock(OrchestratorService::class, function ($mock) {
            $mock->shouldReceive('restart')->once();
        });

        (new ResolveConflictsJob($this->run))->handle(
            app(ImplementAgent::class),
            app(OrchestratorService::class),
        );

        $this->assertNotNull($capturedDoc);
        $this->assertStringContainsString('Resolve Merge Conflicts', $capturedDoc);
        $this->assertStringContainsString('src/foo.php', $capturedDoc);
    }

    public function test_calls_orchestrator_restart_after_clean_resolution(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (($process->command[1] ?? null) === 'rev-parse' && in_array('MERGE_HEAD', $process->command, true)) {
                return Process::result(output: 'abc', exitCode: 0);
            }
            if (($process->command[1] ?? null) === 'merge' && in_array('--no-commit', $process->command, true)) {
                // Clean merge — no conflicts.
                return Process::result(output: '', exitCode: 0);
            }
            if (($process->command[1] ?? null) === 'diff' && in_array('--diff-filter=U', $process->command, true)) {
                return Process::result(output: '', exitCode: 0);
            }

            return Process::result(output: '', exitCode: 0);
        });

        $orchestrator = $this->mock(OrchestratorService::class, function ($mock) {
            $mock->shouldReceive('restart')->once();
        });

        $this->mock(ImplementAgent::class);

        (new ResolveConflictsJob($this->run))->handle(
            app(ImplementAgent::class),
            app(OrchestratorService::class),
        );

        $run = $this->run->fresh();
        $this->assertFalse($run->has_conflicts);
        $this->assertNull($run->conflict_detected_at);
        $this->assertNull($run->conflict_files);
    }

    public function test_does_nothing_when_run_has_no_conflicts(): void
    {
        $this->run->update(['has_conflicts' => false]);

        Process::fake();

        $this->mock(ImplementAgent::class, function ($mock) {
            $mock->shouldNotReceive('execute');
        });

        $this->mock(OrchestratorService::class, function ($mock) {
            $mock->shouldNotReceive('restart');
        });

        (new ResolveConflictsJob($this->run))->handle(
            app(ImplementAgent::class),
            app(OrchestratorService::class),
        );

        Process::assertNothingRan();
    }
}
