<?php

namespace App\Services;

use App\Enums\StageName;
use App\Events\DiffUpdated;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Services\AiProviders\AiProviderManager;
use App\Support\Logging\PipelineLogger;
use Illuminate\Support\Facades\Process;

class ImplementAgent
{
    private const SHELL_TIMEOUT = 30;

    private const OUTPUT_MAX_BYTES = 51200;

    private const MAX_TOOL_LOOPS = 50;

    private const BLOCKED_COMMANDS = [
        'phpunit', 'pest', 'jest', 'mocha', 'pytest', 'rspec',
        'git push', 'git remote',
        'gh pr create', 'gh pr merge',
        'rm -rf /',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the Implement Agent for Relay, an agentic issue pipeline. Your job is to implement the changes described in the preflight document.

## Available Tools

- `read_file` — read a file's contents
- `write_file` — create or overwrite a file
- `list_files` — list directory contents
- `run_shell` — execute a shell command (no test runners allowed)
- `run_linter` — run the project linter on specified files
- `git_status` — show the current git status
- `git_diff` — show the current diff of uncommitted changes

## Constraints

- All file paths are relative to the worktree root.
- You CANNOT run tests — that is the Verify agent's job.
- You CANNOT push code or create PRs — that is the Release agent's job.
- Shell commands have a 30-second timeout and output cap.
- Work methodically: read the relevant code first, plan your changes, then implement.

## When Done

When you have completed the implementation, call the `implementation_complete` tool with a summary of what you changed. Do NOT call it until all changes are made.
PROMPT;

    private const TOOLS = [
        [
            'name' => 'read_file',
            'description' => 'Read the contents of a file in the worktree.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative file path from worktree root.'],
                ],
                'required' => ['path'],
            ],
        ],
        [
            'name' => 'write_file',
            'description' => 'Write or overwrite a file in the worktree.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative file path from worktree root.'],
                    'content' => ['type' => 'string', 'description' => 'Full file contents to write.'],
                ],
                'required' => ['path', 'content'],
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
            'name' => 'run_shell',
            'description' => 'Execute a shell command in the worktree. Test runners and git push are blocked.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string', 'description' => 'Shell command to execute.'],
                ],
                'required' => ['command'],
            ],
        ],
        [
            'name' => 'run_linter',
            'description' => 'Run the project linter on specified files.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'files' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Relative file paths to lint.',
                    ],
                ],
                'required' => ['files'],
            ],
        ],
        [
            'name' => 'git_status',
            'description' => 'Show the current git status of the worktree.',
            'parameters' => [
                'type' => 'object',
                'properties' => [],
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
            'name' => 'implementation_complete',
            'description' => 'Signal that implementation is complete. Call this when all changes are made.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string', 'description' => 'Summary of changes made.'],
                    'files_changed' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'List of files that were created or modified.',
                    ],
                ],
                'required' => ['summary', 'files_changed'],
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

        $provider = $this->providerManager->resolve(null, StageName::Implement);

        $messages = $this->buildMessages($run, $context);

        $this->recordEvent($stage, 'implement_started', 'implement_agent', [
            'iteration' => $stage->iteration,
            'has_failure_context' => isset($context['failure_report']),
        ]);

        PipelineLogger::event($run, 'implement.execute_started', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
            'has_failure_context' => isset($context['failure_report']),
        ]);

        for ($loop = 0; $loop < self::MAX_TOOL_LOOPS; $loop++) {
            $response = $provider->chat($messages, self::TOOLS, [
                'cwd' => $worktreePath,
                'log_context' => [
                    'run_id' => $run->id,
                    'issue_id' => $run->issue_id,
                    'stage' => $stage->name->value,
                    'iteration' => $stage->iteration,
                    'loop' => $loop,
                ],
            ]);

            if (empty($response['tool_calls'])) {
                $this->recordEvent($stage, 'implement_no_tool_call', 'implement_agent', [
                    'content' => $this->truncate($response['content'] ?? '', 500),
                ]);
                $this->orchestrator->complete($stage);

                return;
            }

            foreach ($response['tool_calls'] as $toolCall) {
                if ($toolCall['name'] === 'implementation_complete') {
                    $this->recordEvent($stage, 'implement_complete', 'implement_agent', [
                        'summary' => $toolCall['arguments']['summary'] ?? '',
                        'files_changed' => $toolCall['arguments']['files_changed'] ?? [],
                    ]);

                    PipelineLogger::event($run, 'implement.complete', [
                        'stage' => $stage->name->value,
                        'iteration' => $stage->iteration,
                        'files_changed_count' => count($toolCall['arguments']['files_changed'] ?? []),
                    ]);

                    $this->broadcastDiff($stage, $worktreePath);
                    $this->orchestrator->complete($stage);

                    return;
                }

                $result = $this->executeTool($toolCall['name'], $toolCall['arguments'], $worktreePath, $stage);

                $this->recordEvent($stage, 'tool_call', 'implement_agent', [
                    'tool' => $toolCall['name'],
                    'arguments' => $this->truncateArguments($toolCall['arguments']),
                    'success' => ! str_starts_with($result, 'Error:'),
                ]);

                if ($toolCall['name'] === 'write_file') {
                    $this->broadcastDiff($stage, $worktreePath);
                }

                $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall]];
                $messages[] = ['role' => 'tool', 'content' => $result, 'tool_call_id' => $toolCall['id']];
            }
        }

        $this->recordEvent($stage, 'implement_loop_limit', 'implement_agent', [
            'max_loops' => self::MAX_TOOL_LOOPS,
        ]);

        PipelineLogger::event($run, 'implement.loop_limit', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
            'max_loops' => self::MAX_TOOL_LOOPS,
        ]);

        $this->orchestrator->fail($stage, 'Implement agent exceeded maximum tool call loops.');
    }

    private function buildMessages(mixed $run, array $context): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
        ];

        $userContent = "# Preflight Document\n\n".($run->preflight_doc ?? 'No preflight document available.')."\n\n";

        if (! empty($context['failure_report'])) {
            $userContent .= "# Previous Verification Failure\n\n";
            $userContent .= "The previous implementation attempt failed verification. Fix the following issues:\n\n";
            if (is_array($context['failure_report'])) {
                foreach ($context['failure_report'] as $failure) {
                    $userContent .= "- {$failure}\n";
                }
            } else {
                $userContent .= $context['failure_report']."\n";
            }
            $userContent .= "\n";
        }

        if (! empty($context['guidance'])) {
            $userContent .= "# User Guidance\n\n".$context['guidance']."\n\n";
        }

        $messages[] = ['role' => 'user', 'content' => $userContent];

        return $messages;
    }

    private function executeTool(string $name, array $arguments, string $worktreePath, Stage $stage): string
    {
        return match ($name) {
            'read_file' => $this->toolReadFile($arguments, $worktreePath),
            'write_file' => $this->toolWriteFile($arguments, $worktreePath),
            'list_files' => $this->toolListFiles($arguments, $worktreePath),
            'run_shell' => $this->toolRunShell($arguments, $worktreePath),
            'run_linter' => $this->toolRunLinter($arguments, $worktreePath),
            'git_status' => $this->toolGitStatus($worktreePath),
            'git_diff' => $this->toolGitDiff($worktreePath),
            default => 'Error: Unknown tool "'.$name.'".',
        };
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

        $content = file_get_contents($path);

        return $this->truncate($content, self::OUTPUT_MAX_BYTES);
    }

    private function toolWriteFile(array $arguments, string $worktreePath): string
    {
        $path = $this->resolvePath($arguments['path'] ?? '', $worktreePath);
        if ($path === null) {
            return 'Error: Path escapes the worktree boundary.';
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $arguments['content'] ?? '');

        return 'File written: '.($arguments['path'] ?? '');
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

    private function toolRunShell(array $arguments, string $worktreePath): string
    {
        $command = $arguments['command'] ?? '';

        if ($this->isBlockedCommand($command)) {
            return 'Error: This command is not allowed. Test runners, git push, and PR creation are restricted.';
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

    private function toolRunLinter(array $arguments, string $worktreePath): string
    {
        $files = $arguments['files'] ?? [];
        if (empty($files)) {
            return 'Error: No files specified.';
        }

        foreach ($files as $file) {
            $resolved = $this->resolvePath($file, $worktreePath);
            if ($resolved === null) {
                return "Error: Path escapes the worktree boundary: {$file}";
            }
        }

        $fileArgs = implode(' ', array_map('escapeshellarg', $files));

        $linterBin = file_exists($worktreePath.'/vendor/bin/pint') ? 'vendor/bin/pint' : 'vendor/bin/php-cs-fixer fix';

        $result = Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['sh', '-c', "{$linterBin} {$fileArgs}"]);

        $output = $result->output().$result->errorOutput();

        return $this->truncate($output, self::OUTPUT_MAX_BYTES) ?: '(no output)';
    }

    private function toolGitStatus(string $worktreePath): string
    {
        $result = Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['git', 'status', '--short']);

        return $result->output() ?: '(clean working tree)';
    }

    private function toolGitDiff(string $worktreePath): string
    {
        $result = Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['git', 'diff']);

        $output = $result->output();

        return $this->truncate($output, self::OUTPUT_MAX_BYTES) ?: '(no changes)';
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

    private function broadcastDiff(Stage $stage, string $worktreePath): void
    {
        $diff = $this->toolGitDiff($worktreePath);

        $statusResult = Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['git', 'diff', '--name-only']);

        $changedFiles = array_filter(explode("\n", $statusResult->output()));

        DiffUpdated::dispatch($stage, $diff, $changedFiles);
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
