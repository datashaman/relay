<?php

namespace App\Services;

use App\Models\OauthToken;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Source;
use App\Models\StageEvent;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException as LaravelProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

class WorktreeService
{
    public function __construct(
        private ?OauthService $oauthService = null,
    ) {}

    public function createWorktree(Run $run, Repository $repository): string
    {
        $this->ensureCloned($repository, $run->issue?->source);

        $this->assertIndexLockNotStale($repository->path);

        $root = $repository->worktree_root ?? $repository->path;
        $worktreePath = $root.'/relay-'.$run->id;
        $branch = $run->branch ?? 'relay/run-'.$run->id;

        $this->runGit(
            $repository->path,
            ['worktree', 'add', '-b', $branch, $worktreePath, $repository->default_branch],
        )->throw();

        $run->update([
            'worktree_path' => $worktreePath,
            'branch' => $branch,
        ]);

        if ($repository->setup_script) {
            $this->runScript($repository->setup_script, $run, $repository, $worktreePath);
        }

        return $worktreePath;
    }

    /**
     * Ensure the repository has a local clone. Called lazily the first time
     * a run needs a worktree. If path is already set we trust it; otherwise
     * clone from GitHub into {relay.repos_root}/{owner}/{repo} and record
     * path + default_branch on the repository row.
     */
    public function ensureCloned(Repository $repository, ?Source $source = null): void
    {
        if ($repository->path) {
            return;
        }

        $root = rtrim(config('relay.repos_root'), '/');
        $target = $root.'/'.$repository->name;

        if (! is_dir($target.'/.git')) {
            @mkdir(dirname($target), 0755, true);

            $cloneUrl = $this->buildCloneUrl($repository, $source);

            $result = $this->runGit(null, ['clone', '--quiet', $cloneUrl, $target]);

            if (! $result->successful()) {
                // Scrub the token before surfacing the error.
                $safeStderr = preg_replace('/x-access-token:[^@]+@/', 'x-access-token:***@', $result->errorOutput());
                throw new \RuntimeException(
                    "git clone failed (exit {$result->exitCode()}). stderr: {$safeStderr}"
                );
            }
        }

        $defaultBranch = $repository->default_branch ?: $this->resolveDefaultBranch($target);

        $repository->update([
            'path' => $target,
            'default_branch' => $defaultBranch,
        ]);
    }

    private function buildCloneUrl(Repository $repository, ?Source $source): string
    {
        if ($source && $source->type->value === 'github') {
            $token = $source->oauthTokens()->where('provider', 'github')->first();
            if ($token instanceof OauthToken && $token->access_token) {
                // Refresh first so a stale token doesn't make `git clone` prompt
                // for credentials (which would hang indefinitely even with
                // GIT_TERMINAL_PROMPT=0 if the underlying auth negotiates).
                $oauth = $this->oauthService ?? app(OauthService::class);
                try {
                    $token = $oauth->refreshIfExpired($token);
                } catch (\Throwable) {
                    // Fall through with the existing token; clone will fail fast
                    // on auth rather than hang, thanks to batch-mode env.
                }

                return "https://x-access-token:{$token->access_token}@github.com/{$repository->name}.git";
            }
        }

        // No GitHub source / token available — fall back to SSH and hope the worker has an agent.
        return 'git@github.com:'.$repository->name.'.git';
    }

    private function resolveDefaultBranch(string $path): string
    {
        $result = $this->runGit($path, ['symbolic-ref', '--short', 'HEAD']);

        return trim($result->output()) ?: 'main';
    }

    public function removeWorktree(Run $run, Repository $repository): void
    {
        if ($repository->teardown_script && $run->worktree_path) {
            $this->runScript($repository->teardown_script, $run, $repository, $run->worktree_path);
        }

        if ($run->worktree_path) {
            $this->runGit(
                $repository->path,
                ['worktree', 'remove', '--force', $run->worktree_path],
            )->throw();

            $run->update(['worktree_path' => null]);
        }
    }

    public function runRunScript(Run $run, Repository $repository): ?string
    {
        if (! $repository->run_script || ! $run->worktree_path) {
            return null;
        }

        return $this->runScript($repository->run_script, $run, $repository, $run->worktree_path);
    }

    public function recoverStaleWorktrees(Repository $repository): array
    {
        $result = $this->runGit($repository->path, ['worktree', 'list', '--porcelain']);

        if (! $result->successful()) {
            return [];
        }

        $recovered = [];
        $lines = explode("\n", $result->output());
        $currentPath = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $currentPath = substr($line, 9);
            }

            if ($line === '' && $currentPath !== null) {
                if ($this->isRelayWorktree($currentPath) && $this->isStale($currentPath)) {
                    $this->runGit(
                        $repository->path,
                        ['worktree', 'remove', '--force', $currentPath],
                    );
                    $recovered[] = $currentPath;
                }
                $currentPath = null;
            }
        }

        if ($currentPath !== null && $this->isRelayWorktree($currentPath) && $this->isStale($currentPath)) {
            $this->runGit(
                $repository->path,
                ['worktree', 'remove', '--force', $currentPath],
            );
            $recovered[] = $currentPath;
        }

        return $recovered;
    }

    protected function buildEnv(Run $run, Repository $repository, string $worktreePath): array
    {
        return [
            'RELAY_RUN_ID' => (string) $run->id,
            'RELAY_ISSUE_ID' => (string) $run->issue_id,
            'RELAY_BRANCH' => $run->branch ?? '',
            'RELAY_WORKTREE' => $worktreePath,
        ];
    }

    protected function runScript(string $script, Run $run, Repository $repository, string $worktreePath): string
    {
        $env = $this->buildEnv($run, $repository, $worktreePath);

        $result = Process::path($worktreePath)
            ->env($env)
            ->timeout(300)
            ->run(['sh', '-c', $script]);

        $output = $result->output().$result->errorOutput();

        $this->recordScriptEvent($run, $script, $output, $result->exitCode());

        if (! $result->successful()) {
            $result->throw();
        }

        return $output;
    }

    /**
     * Run a git subprocess with a hard timeout and batch-mode env so it
     * never prompts for credentials or hangs on SSH handshake. A timeout
     * is converted into a RuntimeException with a clear message instead
     * of the Symfony-level exception, so the run's failure reason is
     * specific rather than generic.
     */
    private function runGit(?string $path, array $args, ?int $timeout = null): ProcessResult
    {
        $timeout ??= (int) config('relay.worktree.git_timeout', 60);

        $pending = Process::env($this->gitEnv())->timeout($timeout);
        if ($path !== null) {
            $pending = $pending->path($path);
        }

        $command = array_merge(['git'], $args);

        try {
            return $pending->run($command);
        } catch (LaravelProcessTimedOutException|SymfonyProcessTimedOutException $e) {
            throw new \RuntimeException(
                "git command timed out after {$timeout}s: ".implode(' ', $command),
                previous: $e,
            );
        }
    }

    private function gitEnv(): array
    {
        return [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_SSH_COMMAND' => 'ssh -oBatchMode=yes -oStrictHostKeyChecking=accept-new',
        ];
    }

    /**
     * If the cache repo has a `.git/index.lock` older than the configured
     * threshold, fail fast instead of waiting on `git worktree add` to
     * block behind it. A fresh lock means another git op is in flight and
     * we should let it finish, so we only abort on stale locks.
     */
    private function assertIndexLockNotStale(?string $repoPath): void
    {
        if (! $repoPath) {
            return;
        }

        $lock = $repoPath.'/.git/index.lock';
        if (! is_file($lock)) {
            return;
        }

        $threshold = (int) config('relay.worktree.stale_lock_seconds', 300);
        $age = time() - (int) @filemtime($lock);
        if ($age < $threshold) {
            return;
        }

        throw new \RuntimeException(
            "Stale git index.lock at {$lock} (age {$age}s, threshold {$threshold}s). Remove it and retry."
        );
    }

    protected function recordScriptEvent(Run $run, string $script, string $output, int $exitCode): void
    {
        $stage = $run->stages()->latest()->first();
        if (! $stage) {
            return;
        }

        StageEvent::create([
            'stage_id' => $stage->id,
            'type' => 'script_output',
            'actor' => 'system',
            'payload' => [
                'script' => $script,
                'output' => $output,
                'exit_code' => $exitCode,
            ],
        ]);
    }

    protected function isRelayWorktree(string $path): bool
    {
        return (bool) preg_match('/\/relay-\d+$/', $path);
    }

    protected function isStale(string $path): bool
    {
        $runId = $this->extractRunId($path);
        if ($runId === null) {
            return false;
        }

        $run = Run::find($runId);

        return $run === null || $run->worktree_path !== $path;
    }

    protected function extractRunId(string $path): ?int
    {
        if (preg_match('/\/relay-(\d+)$/', $path, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
