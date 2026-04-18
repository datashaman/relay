<?php

namespace App\Services;

use App\Enums\StageName;
use App\Events\ReleaseProgressUpdated;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Services\AiProviders\AiProviderManager;
use App\Support\Logging\PipelineLogger;
use Illuminate\Support\Facades\Process;

class ReleaseAgent
{
    private const SHELL_TIMEOUT = 120;

    private const OUTPUT_MAX_BYTES = 51200;

    private const MAX_TOOL_LOOPS = 20;

    private const BLOCKED_COMMANDS = [
        'rm -rf /',
    ];

    private const WRITE_COMMANDS = [
        'sed ', 'awk ', 'tee ', 'dd ',
        '> ', '>> ',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the Release Agent for Relay, an agentic issue pipeline. Your job is to commit the Implement agent's changes, push the branch, open a pull request, and optionally update the changelog and trigger a deploy.

## Available Tools

- `git_commit` — stage all changes and commit with a message you generate
- `git_push` — push the branch to the remote
- `create_pr` — create a pull request on GitHub
- `write_changelog` — append an entry to the project's changelog file
- `trigger_deploy` — fire the deploy hook (optional, only if configured)

## Read-Only Investigation Tools

- `read_file` — read a file's contents
- `list_files` — list directory contents
- `git_diff` — show the current diff (staged + unstaged)
- `git_log` — show recent commit history
- `run_shell` — execute a read-only shell command (no file edits allowed)

## Constraints

- You CANNOT modify source code — only commit and push what the Implement agent produced.
- Shell commands that write files are blocked.
- Generate the commit message and PR body from the preflight document and the diff.
- The PR body should include: summary, changes made, and acceptance criteria from the preflight doc.
- The changelog entry should be concise: date, issue title, and a one-line summary of changes.

## Workflow

1. Review the diff and preflight doc to understand the changes.
2. Commit all changes with a descriptive message.
3. Push the branch to the remote.
4. Create a pull request.
5. Optionally write a changelog entry if a changelog file exists.
6. Optionally trigger deploy if configured.
7. Call `release_complete` when done.

## When Done

Call `release_complete` with the PR URL and a summary of what was released.
PROMPT;

    private const TOOLS = [
        [
            'name' => 'git_commit',
            'description' => 'Stage all changes and create a commit with the given message.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string', 'description' => 'The commit message. Should summarize the changes based on the preflight doc.'],
                ],
                'required' => ['message'],
            ],
        ],
        [
            'name' => 'git_push',
            'description' => 'Push the current branch to the remote origin.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'force' => ['type' => 'boolean', 'description' => 'Whether to force push. Defaults to false.'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'create_pr',
            'description' => 'Create a pull request on GitHub for the current branch.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'PR title.'],
                    'body' => ['type' => 'string', 'description' => 'PR body in Markdown. Include summary, changes, and acceptance criteria.'],
                    'base' => ['type' => 'string', 'description' => 'Base branch for the PR (e.g., main).'],
                ],
                'required' => ['title', 'body'],
            ],
        ],
        [
            'name' => 'write_changelog',
            'description' => 'Append an entry to the project changelog file.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'entry' => ['type' => 'string', 'description' => 'The changelog entry in Markdown. Include date, issue title, and summary of changes.'],
                ],
                'required' => ['entry'],
            ],
        ],
        [
            'name' => 'trigger_deploy',
            'description' => 'Trigger the configured deploy hook. Only works if a deploy hook URL is configured.',
            'parameters' => [
                'type' => 'object',
                'properties' => [],
            ],
        ],
        [
            'name' => 'read_file',
            'description' => 'Read the contents of a file in the worktree (read-only).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative file path from worktree root.'],
                ],
                'required' => ['path'],
            ],
        ],
        [
            'name' => 'list_files',
            'description' => 'List files and directories at a path in the worktree.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative directory path from worktree root. Use "." for root.'],
                ],
                'required' => ['path'],
            ],
        ],
        [
            'name' => 'git_diff',
            'description' => 'Show the current diff of uncommitted changes in the worktree.',
            'parameters' => [
                'type' => 'object',
                'properties' => [],
            ],
        ],
        [
            'name' => 'git_log',
            'description' => 'Show recent commit history.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'count' => ['type' => 'integer', 'description' => 'Number of commits to show. Defaults to 10.'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'run_shell',
            'description' => 'Execute a read-only shell command in the worktree. File writes and destructive commands are blocked.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string', 'description' => 'Shell command to execute.'],
                ],
                'required' => ['command'],
            ],
        ],
        [
            'name' => 'release_complete',
            'description' => 'Signal that the release process is complete.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'pr_url' => ['type' => 'string', 'description' => 'URL of the created pull request.'],
                    'summary' => ['type' => 'string', 'description' => 'Summary of what was released.'],
                ],
                'required' => ['summary'],
            ],
        ],
    ];

    public function __construct(
        private AiProviderManager $providerManager,
        private OrchestratorService $orchestrator,
    ) {}

    public function execute(Stage $stage, array $context = []): void
    {
        $run = $stage->run;
        $worktreePath = $run->worktree_path;

        if (! $worktreePath) {
            $this->orchestrator->fail($stage, 'No worktree path configured for this run.');

            return;
        }

        $provider = $this->providerManager->resolve(null, StageName::Release);

        $messages = $this->buildMessages($run, $worktreePath);

        $this->recordEvent($stage, 'release_started', 'release_agent', [
            'iteration' => $stage->iteration,
            'branch' => $run->branch,
        ]);

        PipelineLogger::event($run, 'release.execute_started', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
            'branch' => $run->branch,
        ]);

        for ($loop = 0; $loop < self::MAX_TOOL_LOOPS; $loop++) {
            $response = $provider->chat($messages, self::TOOLS, ['cwd' => $worktreePath]);

            if (empty($response['tool_calls'])) {
                $this->recordEvent($stage, 'release_no_tool_call', 'release_agent', [
                    'content' => $this->truncate($response['content'] ?? '', 500),
                ]);
                $this->orchestrator->complete($stage);

                return;
            }

            foreach ($response['tool_calls'] as $toolCall) {
                if ($toolCall['name'] === 'release_complete') {
                    $this->handleReleaseComplete($stage, $toolCall['arguments']);

                    return;
                }

                $result = $this->executeTool($toolCall['name'], $toolCall['arguments'], $worktreePath, $stage, $run);

                $this->recordEvent($stage, 'tool_call', 'release_agent', [
                    'tool' => $toolCall['name'],
                    'arguments' => $this->truncateArguments($toolCall['arguments']),
                    'success' => ! str_starts_with($result, 'Error:'),
                ]);

                $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall]];
                $messages[] = ['role' => 'tool', 'content' => $result, 'tool_call_id' => $toolCall['id']];
            }
        }

        $this->recordEvent($stage, 'release_loop_limit', 'release_agent', [
            'max_loops' => self::MAX_TOOL_LOOPS,
        ]);

        PipelineLogger::event($run, 'release.loop_limit', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
            'max_loops' => self::MAX_TOOL_LOOPS,
        ]);

        $this->orchestrator->fail($stage, 'Release agent exceeded maximum tool call loops.');
    }

    private function buildMessages(mixed $run, string $worktreePath): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
        ];

        $userContent = "# Preflight Document\n\n".($run->preflight_doc ?? 'No preflight document available.')."\n\n";

        $diffResult = Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'diff']);

        $diff = $this->truncate($diffResult->output(), self::OUTPUT_MAX_BYTES);
        $userContent .= "# Current Implementation Diff\n\n```diff\n{$diff}\n```\n\n";

        $userContent .= "# Run Context\n\n";
        $userContent .= "- **Branch:** {$run->branch}\n";
        $userContent .= "- **Issue:** {$run->issue->title}\n";

        $repository = $run->repository ?? $run->issue->repository;
        if ($repository) {
            $userContent .= "- **Default branch:** {$repository->default_branch}\n";
        }

        $changelogPath = config('relay.changelog_path', 'CHANGELOG.md');
        $hasChangelog = file_exists($worktreePath.'/'.$changelogPath);
        $userContent .= '- **Changelog:** '.($hasChangelog ? $changelogPath : 'none detected')."\n";

        $hasDeployHook = ! empty(config('relay.deploy_hook'));
        $userContent .= '- **Deploy hook:** '.($hasDeployHook ? 'configured' : 'not configured')."\n\n";

        $userContent .= 'Please commit the changes, push the branch, and create a pull request. ';
        if ($hasChangelog) {
            $userContent .= 'Update the changelog. ';
        }
        if ($hasDeployHook) {
            $userContent .= 'Trigger deploy after creating the PR. ';
        }

        $messages[] = ['role' => 'user', 'content' => $userContent];

        return $messages;
    }

    private function handleReleaseComplete(Stage $stage, array $arguments): void
    {
        $prUrl = $arguments['pr_url'] ?? null;
        $summary = $arguments['summary'] ?? '';

        $this->recordEvent($stage, 'release_complete', 'release_agent', [
            'pr_url' => $prUrl,
            'summary' => $summary,
        ]);

        PipelineLogger::event($stage->run, 'release.complete', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
            'pr_url' => $prUrl,
        ]);

        ReleaseProgressUpdated::dispatch($stage, 'complete', $summary);

        $this->orchestrator->complete($stage, ['pr_url' => $prUrl]);
    }

    private function executeTool(string $name, array $arguments, string $worktreePath, Stage $stage, mixed $run): string
    {
        return match ($name) {
            'git_commit' => $this->toolGitCommit($arguments, $worktreePath, $stage),
            'git_push' => $this->toolGitPush($arguments, $worktreePath, $stage, $run),
            'create_pr' => $this->toolCreatePr($arguments, $stage, $run),
            'write_changelog' => $this->toolWriteChangelog($arguments, $worktreePath, $stage),
            'trigger_deploy' => $this->toolTriggerDeploy($stage),
            'read_file' => $this->toolReadFile($arguments, $worktreePath),
            'list_files' => $this->toolListFiles($arguments, $worktreePath),
            'git_diff' => $this->toolGitDiff($worktreePath),
            'git_log' => $this->toolGitLog($arguments, $worktreePath),
            'run_shell' => $this->toolRunShell($arguments, $worktreePath),
            default => 'Error: Unknown tool "'.$name.'".',
        };
    }

    private function toolGitCommit(array $arguments, string $worktreePath, Stage $stage): string
    {
        $message = $arguments['message'] ?? '';
        if (empty($message)) {
            return 'Error: Commit message is required.';
        }

        $addResult = Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'add', '-A']);

        if (! $addResult->successful()) {
            return 'Error: Failed to stage changes: '.$addResult->errorOutput();
        }

        $commitResult = Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'commit', '-m', $message]);

        if (! $commitResult->successful()) {
            return 'Error: Failed to commit: '.$commitResult->errorOutput();
        }

        ReleaseProgressUpdated::dispatch($stage, 'committed', $message);

        return 'Changes committed: '.$this->truncate($commitResult->output(), 500);
    }

    private function toolGitPush(array $arguments, string $worktreePath, Stage $stage, mixed $run): string
    {
        $branch = $run->branch;
        if (empty($branch)) {
            return 'Error: No branch configured for this run.';
        }

        $cmd = ['git', 'push', 'origin', $branch];
        if (! empty($arguments['force'])) {
            $cmd = ['git', 'push', '--force', 'origin', $branch];
        }

        $result = Process::path($worktreePath)
            ->timeout(60)
            ->run($cmd);

        if (! $result->successful()) {
            return 'Error: Failed to push: '.$result->errorOutput();
        }

        ReleaseProgressUpdated::dispatch($stage, 'pushed', "Pushed branch {$branch} to origin");

        return "Pushed branch {$branch} to origin.\n".$result->output().$result->errorOutput();
    }

    private function toolCreatePr(array $arguments, Stage $stage, mixed $run): string
    {
        $title = $arguments['title'] ?? '';
        $body = $arguments['body'] ?? '';
        $base = $arguments['base'] ?? null;

        if (empty($title)) {
            return 'Error: PR title is required.';
        }

        $issue = $run->issue;
        $source = $issue->source;

        if (! $source) {
            return 'Error: No source configured for this issue.';
        }

        $token = $source->oauthTokens()->first();
        if (! $token) {
            return 'Error: No OAuth token available for this source.';
        }

        $repository = $run->repository ?? $issue->repository;
        if (! $repository) {
            return 'Error: No repository configured for this issue.';
        }

        $repoName = $repository->name;
        $parts = explode('/', $repoName);
        if (count($parts) !== 2) {
            return "Error: Repository name must be in owner/repo format, got: {$repoName}";
        }

        [$owner, $repo] = $parts;
        $base = $base ?? $repository->default_branch ?? 'main';

        $client = new GitHubClient($token, app(OauthService::class));

        try {
            $pr = $client->createPullRequest($owner, $repo, $title, $run->branch, $base, $body);
        } catch (\Throwable $e) {
            return 'Error: Failed to create PR: '.$e->getMessage();
        }

        $prUrl = $pr['html_url'] ?? '';

        $this->recordEvent($stage, 'pr_created', 'release_agent', [
            'pr_url' => $prUrl,
            'pr_number' => $pr['number'] ?? null,
            'title' => $title,
        ]);

        PipelineLogger::event($run, 'release.pr_created', [
            'stage' => $stage->name->value,
            'pr_url' => $prUrl,
            'pr_number' => $pr['number'] ?? null,
        ]);

        ReleaseProgressUpdated::dispatch($stage, 'pr_created', $prUrl);

        return "Pull request created: {$prUrl}";
    }

    private function toolWriteChangelog(array $arguments, string $worktreePath, Stage $stage): string
    {
        $entry = $arguments['entry'] ?? '';
        if (empty($entry)) {
            return 'Error: Changelog entry is required.';
        }

        $changelogPath = config('relay.changelog_path', 'CHANGELOG.md');
        $fullPath = $worktreePath.'/'.$changelogPath;

        $existing = file_exists($fullPath) ? file_get_contents($fullPath) : '';
        $newContent = $entry."\n\n".$existing;

        file_put_contents($fullPath, $newContent);

        ReleaseProgressUpdated::dispatch($stage, 'changelog_updated', $changelogPath);

        return "Changelog updated at {$changelogPath}.";
    }

    private function toolTriggerDeploy(Stage $stage): string
    {
        $hook = config('relay.deploy_hook');

        if (empty($hook)) {
            return 'No deploy hook configured. Skipping.';
        }

        $result = Process::timeout(30)
            ->run(['sh', '-c', $hook]);

        $output = $this->truncate($result->output().$result->errorOutput(), self::OUTPUT_MAX_BYTES);

        $this->recordEvent($stage, 'deploy_triggered', 'release_agent', [
            'hook' => $hook,
            'exit_code' => $result->exitCode(),
            'success' => $result->successful(),
        ]);

        PipelineLogger::event($stage->run, 'release.deploy_triggered', [
            'stage' => $stage->name->value,
            'exit_code' => $result->exitCode(),
            'success' => $result->successful(),
        ]);

        ReleaseProgressUpdated::dispatch($stage, 'deploy_triggered', $result->successful() ? 'success' : 'failed');

        if (! $result->successful()) {
            return "Deploy hook failed (exit code {$result->exitCode()}):\n{$output}";
        }

        return "Deploy hook triggered successfully.\n{$output}";
    }

    private function toolReadFile(array $arguments, string $worktreePath): string
    {
        $path = $this->resolvePath($arguments['path'] ?? '', $worktreePath);
        if ($path === null) {
            return 'Error: Path escapes the worktree boundary.';
        }

        if (! file_exists($path)) {
            return 'Error: File not found: '.($arguments['path'] ?? '');
        }

        return $this->truncate(file_get_contents($path), self::OUTPUT_MAX_BYTES);
    }

    private function toolListFiles(array $arguments, string $worktreePath): string
    {
        $path = $this->resolvePath($arguments['path'] ?? '.', $worktreePath);
        if ($path === null) {
            return 'Error: Path escapes the worktree boundary.';
        }

        if (! is_dir($path)) {
            return 'Error: Directory not found: '.($arguments['path'] ?? '');
        }

        $entries = scandir($path);
        $lines = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path.'/'.$entry;
            $lines[] = is_dir($full) ? $entry.'/' : $entry;
        }

        return implode("\n", $lines) ?: '(empty directory)';
    }

    private function toolGitDiff(string $worktreePath): string
    {
        $result = Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'diff']);

        $staged = Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'diff', '--staged']);

        $output = '';
        if ($result->output()) {
            $output .= "Unstaged:\n".$result->output();
        }
        if ($staged->output()) {
            $output .= "\nStaged:\n".$staged->output();
        }

        return $this->truncate($output, self::OUTPUT_MAX_BYTES) ?: '(no changes)';
    }

    private function toolGitLog(array $arguments, string $worktreePath): string
    {
        $count = min($arguments['count'] ?? 10, 50);

        $result = Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'log', '--oneline', "-{$count}"]);

        return $this->truncate($result->output(), self::OUTPUT_MAX_BYTES) ?: '(no commits)';
    }

    private function toolRunShell(array $arguments, string $worktreePath): string
    {
        $command = $arguments['command'] ?? '';

        if ($this->isBlockedCommand($command)) {
            return 'Error: This command is not allowed.';
        }

        if ($this->isWriteCommand($command)) {
            return 'Error: File write commands are not allowed. The Release agent cannot modify source code.';
        }

        $result = Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['sh', '-c', $command]);

        $output = $result->output().$result->errorOutput();
        $output = $this->truncate($output, self::OUTPUT_MAX_BYTES);

        if (! $result->successful()) {
            return "Command failed (exit code {$result->exitCode()}):\n{$output}";
        }

        return $output ?: '(no output)';
    }

    private function resolvePath(string $relative, string $worktreePath): ?string
    {
        $relative = ltrim($relative, '/');

        $combined = $worktreePath.'/'.$relative;

        $resolved = realpath(dirname($combined));
        if ($resolved === false) {
            $resolved = realpath($worktreePath);
            if ($resolved === false) {
                return null;
            }
            $resolved .= '/'.basename($combined);
        } else {
            $resolved .= '/'.basename($combined);
        }

        $realWorktree = realpath($worktreePath);
        if ($realWorktree === false) {
            return null;
        }

        if (! str_starts_with($resolved, $realWorktree)) {
            return null;
        }

        return $combined;
    }

    private function isBlockedCommand(string $command): bool
    {
        $lower = strtolower($command);
        foreach (self::BLOCKED_COMMANDS as $blocked) {
            if (str_contains($lower, $blocked)) {
                return true;
            }
        }

        return false;
    }

    private function isWriteCommand(string $command): bool
    {
        $lower = strtolower(trim($command));
        foreach (self::WRITE_COMMANDS as $write) {
            if (str_contains($lower, $write)) {
                return true;
            }
        }

        return false;
    }

    private function truncate(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return substr($text, 0, $maxBytes)."\n... (truncated at {$maxBytes} bytes)";
    }

    private function truncateArguments(array $arguments): array
    {
        $truncated = [];
        foreach ($arguments as $key => $value) {
            if (is_string($value) && strlen($value) > 200) {
                $truncated[$key] = substr($value, 0, 200).'...';
            } else {
                $truncated[$key] = $value;
            }
        }

        return $truncated;
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
