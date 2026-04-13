<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\Run;
use App\Models\StageEvent;
use Illuminate\Support\Facades\Process;

class WorktreeService
{
    public function createWorktree(Run $run, Repository $repository): string
    {
        $this->ensureCloned($repository);

        $root = $repository->worktree_root ?? $repository->path;
        $worktreePath = $root . '/relay-' . $run->id;
        $branch = $run->branch ?? 'relay/run-' . $run->id;

        Process::path($repository->path)
            ->run(['git', 'worktree', 'add', '-b', $branch, $worktreePath, $repository->default_branch])
            ->throw();

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
    public function ensureCloned(Repository $repository): void
    {
        if ($repository->path) {
            return;
        }

        $root = rtrim(config('relay.repos_root'), '/');
        $target = $root . '/' . $repository->name;

        if (! is_dir($target . '/.git')) {
            @mkdir(dirname($target), 0755, true);

            $cloneUrl = 'git@github.com:' . $repository->name . '.git';

            Process::env($this->sshEnv())
                ->run(['git', 'clone', '--quiet', $cloneUrl, $target])
                ->throw();
        }

        $defaultBranch = $repository->default_branch ?: $this->resolveDefaultBranch($target);

        $repository->update([
            'path' => $target,
            'default_branch' => $defaultBranch,
        ]);
    }

    private function resolveDefaultBranch(string $path): string
    {
        $result = Process::path($path)->run(['git', 'symbolic-ref', '--short', 'HEAD']);

        return trim($result->output()) ?: 'main';
    }

    /**
     * Env vars needed for SSH-auth git operations inside the queue worker:
     * the agent socket (so private-repo clones work) and HOME so
     * ~/.ssh/config and known_hosts resolve.
     */
    private function sshEnv(): array
    {
        return array_filter([
            'SSH_AUTH_SOCK' => getenv('SSH_AUTH_SOCK') ?: null,
            'HOME' => getenv('HOME') ?: null,
            'PATH' => getenv('PATH') ?: null,
        ]);
    }

    public function removeWorktree(Run $run, Repository $repository): void
    {
        if ($repository->teardown_script && $run->worktree_path) {
            $this->runScript($repository->teardown_script, $run, $repository, $run->worktree_path);
        }

        if ($run->worktree_path) {
            Process::path($repository->path)
                ->run(['git', 'worktree', 'remove', '--force', $run->worktree_path])
                ->throw();

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
        $result = Process::path($repository->path)
            ->run(['git', 'worktree', 'list', '--porcelain']);

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
                    Process::path($repository->path)
                        ->run(['git', 'worktree', 'remove', '--force', $currentPath]);
                    $recovered[] = $currentPath;
                }
                $currentPath = null;
            }
        }

        if ($currentPath !== null && $this->isRelayWorktree($currentPath) && $this->isStale($currentPath)) {
            Process::path($repository->path)
                ->run(['git', 'worktree', 'remove', '--force', $currentPath]);
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

        $output = $result->output() . $result->errorOutput();

        $this->recordScriptEvent($run, $script, $output, $result->exitCode());

        if (! $result->successful()) {
            $result->throw();
        }

        return $output;
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
