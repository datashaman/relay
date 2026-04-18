<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Stage;
use App\Services\WorktreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Tests\TestCase;

class WorktreeServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorktreeService $service;

    private Repository $repository;

    private Run $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new WorktreeService;

        $this->repository = Repository::factory()->create([
            'path' => '/repos/my-project',
            'default_branch' => 'main',
            'worktree_root' => '/worktrees',
        ]);

        $issue = Issue::factory()->create(['repository_id' => $this->repository->id]);

        $this->run = Run::factory()->create([
            'issue_id' => $issue->id,
            'branch' => 'relay/fix-123',
        ]);

        Stage::factory()->create(['run_id' => $this->run->id]);
    }

    public function test_creates_worktree_at_configured_root(): void
    {
        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $path = $this->service->createWorktree($this->run, $this->repository);

        $this->assertEquals('/worktrees/relay-'.$this->run->id, $path);
        $this->run->refresh();
        $this->assertEquals($path, $this->run->worktree_path);
        $this->assertEquals('relay/fix-123', $this->run->branch);

        Process::assertRan(function (PendingProcess $process) {
            return $process->command === ['git', 'worktree', 'add', '-b', 'relay/fix-123', '/worktrees/relay-'.$this->run->id, 'main']
                && $process->path === '/repos/my-project';
        });
    }

    public function test_uses_repo_path_when_no_worktree_root(): void
    {
        $this->repository->update(['worktree_root' => null]);

        Process::fake(['*' => Process::result(output: '')]);

        $path = $this->service->createWorktree($this->run, $this->repository);

        $this->assertEquals('/repos/my-project/relay-'.$this->run->id, $path);
    }

    public function test_generates_branch_name_when_run_has_none(): void
    {
        $this->run->update(['branch' => null]);

        Process::fake(['*' => Process::result(output: '')]);

        $this->service->createWorktree($this->run, $this->repository);

        $this->run->refresh();
        $this->assertEquals('relay/run-'.$this->run->id, $this->run->branch);
    }

    public function test_runs_setup_script_after_worktree_creation(): void
    {
        $this->repository->update(['setup_script' => 'npm install']);

        Process::fake(['*' => Process::result(output: 'installed deps')]);

        $this->service->createWorktree($this->run, $this->repository);

        Process::assertRan(function (PendingProcess $process) {
            return $process->command === ['sh', '-c', 'npm install']
                && $process->path === '/worktrees/relay-'.$this->run->id
                && $process->environment['RELAY_RUN_ID'] === (string) $this->run->id
                && $process->environment['RELAY_ISSUE_ID'] === (string) $this->run->issue_id
                && $process->environment['RELAY_BRANCH'] === 'relay/fix-123'
                && $process->environment['RELAY_WORKTREE'] === '/worktrees/relay-'.$this->run->id;
        });
    }

    public function test_skips_setup_when_no_script_configured(): void
    {
        $this->repository->update(['setup_script' => null]);

        $ran = [];
        Process::fake(function (PendingProcess $process) use (&$ran) {
            $ran[] = $process->command;

            return Process::result(output: '');
        });

        $this->service->createWorktree($this->run, $this->repository);

        $this->assertCount(1, $ran);
        $this->assertEquals('git', $ran[0][0]);
    }

    public function test_removes_worktree_after_teardown(): void
    {
        $worktreePath = '/worktrees/relay-'.$this->run->id;
        $this->run->update(['worktree_path' => $worktreePath]);

        Process::fake(['*' => Process::result(output: '')]);

        $this->service->removeWorktree($this->run, $this->repository);

        Process::assertRan(function (PendingProcess $process) use ($worktreePath) {
            return $process->command === ['git', 'worktree', 'remove', '--force', $worktreePath];
        });

        $this->run->refresh();
        $this->assertNull($this->run->worktree_path);
    }

    public function test_runs_teardown_script_before_removal(): void
    {
        $worktreePath = '/worktrees/relay-'.$this->run->id;
        $this->run->update(['worktree_path' => $worktreePath]);
        $this->repository->update(['teardown_script' => 'rm -rf node_modules']);

        $callOrder = [];
        Process::fake(function (PendingProcess $process) use (&$callOrder) {
            if ($process->command === ['sh', '-c', 'rm -rf node_modules']) {
                $callOrder[] = 'teardown';
            }
            if (in_array('remove', $process->command ?? [])) {
                $callOrder[] = 'remove';
            }

            return Process::result(output: '');
        });

        $this->service->removeWorktree($this->run, $this->repository);

        $this->assertEquals(['teardown', 'remove'], $callOrder);
    }

    public function test_script_output_attached_to_stage_events(): void
    {
        $this->repository->update(['setup_script' => 'echo hello']);

        Process::fake(['*' => Process::result(output: 'hello world')]);

        $this->service->createWorktree($this->run, $this->repository);

        $stage = $this->run->stages()->latest()->first();
        $event = $stage->events()->where('type', 'script_output')->first();

        $this->assertNotNull($event);
        $this->assertEquals('system', $event->actor);
        $this->assertEquals('echo hello', $event->payload['script']);
        $this->assertStringContainsString('hello world', $event->payload['output']);
        $this->assertEquals(0, $event->payload['exit_code']);
    }

    public function test_run_script_executes_in_worktree(): void
    {
        $worktreePath = '/worktrees/relay-'.$this->run->id;
        $this->run->update(['worktree_path' => $worktreePath]);
        $this->repository->update(['run_script' => 'make build']);

        Process::fake(['*' => Process::result(output: 'build complete')]);

        $output = $this->service->runRunScript($this->run, $this->repository);

        $this->assertStringContainsString('build complete', $output);

        Process::assertRan(function (PendingProcess $process) use ($worktreePath) {
            return $process->command === ['sh', '-c', 'make build']
                && $process->path === $worktreePath;
        });
    }

    public function test_run_script_returns_null_when_not_configured(): void
    {
        $this->run->update(['worktree_path' => '/worktrees/relay-'.$this->run->id]);
        $this->repository->update(['run_script' => null]);

        Process::fake();

        $result = $this->service->runRunScript($this->run, $this->repository);

        $this->assertNull($result);
        Process::assertNothingRan();
    }

    public function test_environment_variables_set_correctly(): void
    {
        $this->repository->update(['setup_script' => 'env']);

        Process::fake(['*' => Process::result(output: '')]);

        $this->service->createWorktree($this->run, $this->repository);

        Process::assertRan(function (PendingProcess $process) {
            return $process->command === ['sh', '-c', 'env']
                && $process->environment['RELAY_RUN_ID'] === (string) $this->run->id
                && $process->environment['RELAY_ISSUE_ID'] === (string) $this->run->issue_id
                && $process->environment['RELAY_BRANCH'] === 'relay/fix-123'
                && $process->environment['RELAY_WORKTREE'] === '/worktrees/relay-'.$this->run->id;
        });
    }

    public function test_recovers_stale_worktrees(): void
    {
        $porcelainOutput = "worktree /repos/my-project\nHEAD abc123\nbranch refs/heads/main\n\nworktree /worktrees/relay-9999\nHEAD def456\nbranch refs/heads/relay/run-9999\n\n";

        Process::fake(function (PendingProcess $process) use ($porcelainOutput) {
            if (in_array('list', $process->command)) {
                return Process::result(output: $porcelainOutput);
            }

            return Process::result(output: '');
        });

        $recovered = $this->service->recoverStaleWorktrees($this->repository);

        $this->assertCount(1, $recovered);
        $this->assertEquals('/worktrees/relay-9999', $recovered[0]);

        Process::assertRan(function (PendingProcess $process) {
            return $process->command === ['git', 'worktree', 'remove', '--force', '/worktrees/relay-9999'];
        });
    }

    public function test_does_not_recover_active_worktrees(): void
    {
        $this->run->update(['worktree_path' => '/worktrees/relay-'.$this->run->id]);

        $porcelainOutput = "worktree /repos/my-project\nHEAD abc\nbranch refs/heads/main\n\nworktree /worktrees/relay-{$this->run->id}\nHEAD def\nbranch refs/heads/relay/fix\n\n";

        Process::fake([
            '*' => Process::result(output: $porcelainOutput),
        ]);

        $recovered = $this->service->recoverStaleWorktrees($this->repository);

        $this->assertCount(0, $recovered);
    }

    public function test_does_not_recover_non_relay_worktrees(): void
    {
        $porcelainOutput = "worktree /repos/my-project\nHEAD abc\nbranch refs/heads/main\n\nworktree /worktrees/feature-branch\nHEAD def\nbranch refs/heads/feature\n\n";

        Process::fake([
            '*' => Process::result(output: $porcelainOutput),
        ]);

        $recovered = $this->service->recoverStaleWorktrees($this->repository);

        $this->assertCount(0, $recovered);
    }

    public function test_git_commands_set_timeout_and_batch_mode_env(): void
    {
        config([
            'relay.worktree.git_timeout' => 42,
        ]);

        $observed = [];
        Process::fake(function (PendingProcess $process) use (&$observed) {
            if (($process->command[0] ?? null) === 'git') {
                $observed[] = [
                    'timeout' => $process->timeout,
                    'env' => $process->environment,
                ];
            }

            return Process::result(output: '');
        });

        $this->service->createWorktree($this->run, $this->repository);

        $this->assertNotEmpty($observed, 'expected at least one git subprocess');
        foreach ($observed as $call) {
            $this->assertSame(42, $call['timeout']);
            $this->assertSame('0', $call['env']['GIT_TERMINAL_PROMPT'] ?? null);
            $this->assertStringContainsString(
                'BatchMode=yes',
                (string) ($call['env']['GIT_SSH_COMMAND'] ?? ''),
            );
        }
    }

    public function test_hanging_git_subprocess_fails_fast_with_timeout_error(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (($process->command[0] ?? null) === 'git' && in_array('worktree', $process->command, true)) {
                throw new ProcessTimedOutException(
                    new \Symfony\Component\Process\Process(['git']),
                    ProcessTimedOutException::TYPE_GENERAL,
                );
            }

            return Process::result(output: '');
        });

        try {
            $this->service->createWorktree($this->run, $this->repository);
            $this->fail('Expected createWorktree to throw on timeout.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('timed out', strtolower($e->getMessage()));
            $this->assertStringContainsString('git', $e->getMessage());
        }
    }

    public function test_stale_index_lock_fails_fast(): void
    {
        $tmp = sys_get_temp_dir().'/relay-worktree-test-'.uniqid();
        mkdir($tmp.'/.git', 0755, true);
        touch($tmp.'/.git/index.lock', time() - 3600);

        $this->repository->update(['path' => $tmp]);

        config(['relay.worktree.stale_lock_seconds' => 300]);

        Process::fake(['*' => Process::result(output: '')]);

        try {
            $this->service->createWorktree($this->run, $this->repository);
            $this->fail('Expected createWorktree to abort on stale index.lock.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('index.lock', $e->getMessage());
            $this->assertStringContainsString('Stale', $e->getMessage());
        } finally {
            @unlink($tmp.'/.git/index.lock');
            @rmdir($tmp.'/.git');
            @rmdir($tmp);
        }
    }

    public function test_fresh_index_lock_is_tolerated(): void
    {
        $tmp = sys_get_temp_dir().'/relay-worktree-test-'.uniqid();
        mkdir($tmp.'/.git', 0755, true);
        touch($tmp.'/.git/index.lock', time());

        $this->repository->update(['path' => $tmp]);

        config(['relay.worktree.stale_lock_seconds' => 300]);

        Process::fake(['*' => Process::result(output: '')]);

        try {
            $path = $this->service->createWorktree($this->run, $this->repository);
            $this->assertSame('/worktrees/relay-'.$this->run->id, $path);
        } finally {
            @unlink($tmp.'/.git/index.lock');
            @rmdir($tmp.'/.git');
            @rmdir($tmp);
        }
    }
}
