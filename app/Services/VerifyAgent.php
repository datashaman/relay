<?php

namespace App\Services;

use App\Enums\StageName;
use App\Events\TestResultUpdated;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Services\AiProviders\AiProviderManager;
use App\Support\Logging\PipelineLogger;
use Illuminate\Support\Facades\Process;

class VerifyAgent
{
    private const SHELL_TIMEOUT = 120;

    private const OUTPUT_MAX_BYTES = 51200;

    private const MAX_TOOL_LOOPS = 30;

    private const BLOCKED_COMMANDS = [
        'git push', 'git remote',
        'gh pr create', 'gh pr merge',
        'rm -rf /',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the Verify Agent for Relay, an agentic issue pipeline. Your job is to run tests, static analysis, and coverage checks against the Implement agent's output to enforce quality gates before release.

## Available Tools

- `run_tests` — run the project's test suite (configurable per repository)
- `run_static_analysis` — run static analysis tools (e.g., PHPStan, ESLint)
- `coverage_diff` — compare test coverage before and after the implementation

## Read-Only Investigation Tools

- `read_file` — read a file's contents (you CANNOT edit files)
- `list_files` — list directory contents
- `run_shell` — execute a shell command for investigation (no file edits, no git push)
- `git_diff` — show the current diff of uncommitted changes

## Constraints

- You CANNOT edit files — that is the Implement agent's job.
- You CANNOT push code or create PRs — that is the Release agent's job.
- All file paths are relative to the worktree root.
- Shell commands have a timeout and output cap.
- Work methodically: review the diff, run tests, analyze results.

## When Done

When verification is complete, call `verification_complete` with your findings:
- If all gates pass, set `passed` to true.
- If any gate fails, set `passed` to false and provide a structured failure report with test name, assertion, file, and line for each failure.
PROMPT;

    private const TOOLS = [
        [
            'name' => 'run_tests',
            'description' => 'Run the project test suite. Optionally specify a filter to run specific tests.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'filter' => ['type' => 'string', 'description' => 'Optional test filter/pattern to run specific tests.'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'run_static_analysis',
            'description' => 'Run static analysis tools (PHPStan, ESLint, etc.) on the project.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'paths' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Optional specific paths to analyze. Defaults to full project.',
                    ],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'coverage_diff',
            'description' => 'Generate a coverage report showing coverage changes from the implementation.',
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
            'name' => 'run_shell',
            'description' => 'Execute a shell command in the worktree for investigation. File edits and git push are blocked.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string', 'description' => 'Shell command to execute.'],
                ],
                'required' => ['command'],
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
            'name' => 'verification_complete',
            'description' => 'Signal that verification is complete with structured results.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'passed' => ['type' => 'boolean', 'description' => 'Whether all quality gates passed.'],
                    'summary' => ['type' => 'string', 'description' => 'Summary of verification results.'],
                    'failures' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'test' => ['type' => 'string', 'description' => 'Test name or check that failed.'],
                                'assertion' => ['type' => 'string', 'description' => 'What was expected vs actual.'],
                                'file' => ['type' => 'string', 'description' => 'File where the failure occurred.'],
                                'line' => ['type' => 'integer', 'description' => 'Line number of the failure.'],
                            ],
                        ],
                        'description' => 'Structured failure details. Required when passed is false.',
                    ],
                ],
                'required' => ['passed', 'summary'],
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

        $provider = $this->providerManager->resolve(null, StageName::Verify);

        $messages = $this->buildMessages($run, $worktreePath);

        $this->recordEvent($stage, 'verify_started', 'verify_agent', [
            'iteration' => $stage->iteration,
        ]);

        PipelineLogger::event($run, 'verify.execute_started', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
        ]);

        for ($loop = 0; $loop < self::MAX_TOOL_LOOPS; $loop++) {
            $response = $provider->chat($messages, self::TOOLS, ['cwd' => $worktreePath]);

            if (empty($response['tool_calls'])) {
                $this->recordEvent($stage, 'verify_no_tool_call', 'verify_agent', [
                    'content' => $this->truncate($response['content'] ?? '', 500),
                ]);
                $this->orchestrator->complete($stage);

                return;
            }

            foreach ($response['tool_calls'] as $toolCall) {
                if ($toolCall['name'] === 'verification_complete') {
                    $this->handleVerificationComplete($stage, $toolCall['arguments']);

                    return;
                }

                $result = $this->executeTool($toolCall['name'], $toolCall['arguments'], $worktreePath, $stage);

                $this->recordEvent($stage, 'tool_call', 'verify_agent', [
                    'tool' => $toolCall['name'],
                    'arguments' => $this->truncateArguments($toolCall['arguments']),
                    'success' => ! str_starts_with($result, 'Error:'),
                ]);

                $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall]];
                $messages[] = ['role' => 'tool', 'content' => $result, 'tool_call_id' => $toolCall['id']];
            }
        }

        $this->recordEvent($stage, 'verify_loop_limit', 'verify_agent', [
            'max_loops' => self::MAX_TOOL_LOOPS,
        ]);

        PipelineLogger::event($run, 'verify.loop_limit', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
            'max_loops' => self::MAX_TOOL_LOOPS,
        ]);

        $this->orchestrator->fail($stage, 'Verify agent exceeded maximum tool call loops.');
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
        $userContent .= "Please run the test suite and static analysis to verify this implementation meets the acceptance criteria.\n";

        $messages[] = ['role' => 'user', 'content' => $userContent];

        return $messages;
    }

    private function handleVerificationComplete(Stage $stage, array $arguments): void
    {
        $passed = $arguments['passed'] ?? false;
        $summary = $arguments['summary'] ?? '';
        $failures = $arguments['failures'] ?? [];

        $this->recordEvent($stage, 'verify_complete', 'verify_agent', [
            'passed' => $passed,
            'summary' => $summary,
            'failure_count' => count($failures),
            'failures' => $failures,
        ]);

        PipelineLogger::event($stage->run, 'verify.complete', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
            'passed' => $passed,
            'failure_count' => count($failures),
        ]);

        TestResultUpdated::dispatch($stage, $summary, $passed ? 'passed' : 'failed');

        if ($passed) {
            $this->orchestrator->complete($stage);
        } else {
            $failureReport = array_map(function ($failure) {
                $parts = [];
                if (! empty($failure['test'])) {
                    $parts[] = "Test {$failure['test']} failed";
                }
                if (! empty($failure['assertion'])) {
                    $parts[] = $failure['assertion'];
                }
                if (! empty($failure['file'])) {
                    $location = $failure['file'];
                    if (! empty($failure['line'])) {
                        $location .= ":{$failure['line']}";
                    }
                    $parts[] = "at {$location}";
                }

                return implode(': ', $parts);
            }, $failures);

            if (empty($failureReport)) {
                $failureReport = [$summary];
            }

            $this->orchestrator->bounce($stage, $failureReport);
        }
    }

    private function executeTool(string $name, array $arguments, string $worktreePath, Stage $stage): string
    {
        return match ($name) {
            'run_tests' => $this->toolRunTests($arguments, $worktreePath, $stage),
            'run_static_analysis' => $this->toolRunStaticAnalysis($arguments, $worktreePath, $stage),
            'coverage_diff' => $this->toolCoverageDiff($worktreePath, $stage),
            'read_file' => $this->toolReadFile($arguments, $worktreePath),
            'list_files' => $this->toolListFiles($arguments, $worktreePath),
            'run_shell' => $this->toolRunShell($arguments, $worktreePath),
            'git_diff' => $this->toolGitDiff($worktreePath),
            default => 'Error: Unknown tool "'.$name.'".',
        };
    }

    private function toolRunTests(array $arguments, string $worktreePath, Stage $stage): string
    {
        $runner = $this->detectTestRunner($worktreePath);
        if (! $runner) {
            return 'Error: No test runner detected in this project.';
        }

        $command = $runner;
        if (! empty($arguments['filter'])) {
            $filter = escapeshellarg($arguments['filter']);
            $command .= " --filter={$filter}";
        }

        $result = Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['sh', '-c', $command]);

        $output = $result->output().$result->errorOutput();
        $output = $this->truncate($output, self::OUTPUT_MAX_BYTES);

        $status = $result->successful() ? 'passed' : 'failed';

        $this->recordEvent($stage, 'test_results', 'verify_agent', [
            'runner' => $runner,
            'filter' => $arguments['filter'] ?? null,
            'exit_code' => $result->exitCode(),
            'status' => $status,
        ]);

        PipelineLogger::event($stage->run, 'verify.test_results', [
            'stage' => $stage->name->value,
            'runner' => $runner,
            'exit_code' => $result->exitCode(),
            'status' => $status,
        ]);

        TestResultUpdated::dispatch($stage, $output, $status);

        if (! $result->successful()) {
            return "Tests FAILED (exit code {$result->exitCode()}):\n{$output}";
        }

        return "Tests PASSED:\n{$output}";
    }

    private function toolRunStaticAnalysis(array $arguments, string $worktreePath, Stage $stage): string
    {
        $analyzer = $this->detectStaticAnalyzer($worktreePath);
        if (! $analyzer) {
            return 'No static analysis tool detected. Skipping.';
        }

        $command = $analyzer;
        if (! empty($arguments['paths'])) {
            foreach ($arguments['paths'] as $path) {
                $resolved = $this->resolvePath($path, $worktreePath);
                if ($resolved === null) {
                    return "Error: Path escapes the worktree boundary: {$path}";
                }
            }
            $command .= ' '.implode(' ', array_map('escapeshellarg', $arguments['paths']));
        }

        $result = Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['sh', '-c', $command]);

        $output = $result->output().$result->errorOutput();
        $output = $this->truncate($output, self::OUTPUT_MAX_BYTES);

        $this->recordEvent($stage, 'static_analysis_results', 'verify_agent', [
            'analyzer' => $analyzer,
            'exit_code' => $result->exitCode(),
            'status' => $result->successful() ? 'passed' : 'failed',
        ]);

        PipelineLogger::event($stage->run, 'verify.static_analysis_results', [
            'stage' => $stage->name->value,
            'analyzer' => $analyzer,
            'exit_code' => $result->exitCode(),
            'status' => $result->successful() ? 'passed' : 'failed',
        ]);

        if (! $result->successful()) {
            return "Static analysis FAILED (exit code {$result->exitCode()}):\n{$output}";
        }

        return "Static analysis PASSED:\n{$output}";
    }

    private function toolCoverageDiff(string $worktreePath, Stage $stage): string
    {
        $runner = $this->detectTestRunner($worktreePath);
        if (! $runner) {
            return 'Error: No test runner detected for coverage.';
        }

        $command = match (true) {
            str_contains($runner, 'pest'), str_contains($runner, 'phpunit') => "{$runner} --coverage-text",
            str_contains($runner, 'jest') => "{$runner} --coverage --coverageReporters=text",
            default => "{$runner} --coverage",
        };

        $result = Process::path($worktreePath)
            ->timeout(self::SHELL_TIMEOUT)
            ->run(['sh', '-c', $command]);

        $output = $result->output().$result->errorOutput();
        $output = $this->truncate($output, self::OUTPUT_MAX_BYTES);

        $this->recordEvent($stage, 'coverage_results', 'verify_agent', [
            'exit_code' => $result->exitCode(),
        ]);

        return "Coverage report:\n{$output}";
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

    private function toolRunShell(array $arguments, string $worktreePath): string
    {
        $command = $arguments['command'] ?? '';

        if ($this->isBlockedCommand($command)) {
            return 'Error: This command is not allowed. Git push and PR creation are restricted.';
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

    private function toolGitDiff(string $worktreePath): string
    {
        $result = Process::path($worktreePath)
            ->timeout(30)
            ->run(['git', 'diff']);

        return $this->truncate($result->output(), self::OUTPUT_MAX_BYTES) ?: '(no changes)';
    }

    private function detectTestRunner(string $worktreePath): ?string
    {
        if (file_exists($worktreePath.'/vendor/bin/pest')) {
            return 'vendor/bin/pest';
        }
        if (file_exists($worktreePath.'/vendor/bin/phpunit')) {
            return 'vendor/bin/phpunit';
        }
        if (file_exists($worktreePath.'/node_modules/.bin/jest')) {
            return 'npx jest';
        }
        if (file_exists($worktreePath.'/node_modules/.bin/mocha')) {
            return 'npx mocha';
        }
        if (file_exists($worktreePath.'/node_modules/.bin/vitest')) {
            return 'npx vitest run';
        }

        return null;
    }

    private function detectStaticAnalyzer(string $worktreePath): ?string
    {
        if (file_exists($worktreePath.'/vendor/bin/phpstan')) {
            return 'vendor/bin/phpstan analyse';
        }
        if (file_exists($worktreePath.'/node_modules/.bin/eslint')) {
            return 'npx eslint .';
        }

        return null;
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
