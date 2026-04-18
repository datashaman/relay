<?php

namespace App\Services\AiProviders;

use App\Contracts\AiProvider;
use App\Support\Logging\PipelineLogger;
use Symfony\Component\Process\Process;

class ClaudeCodeCliProvider implements AiProvider
{
    public function __construct(
        private string $command = 'claude --dangerously-skip-permissions --print --output-format stream-json --verbose',
        private ?string $workingDirectory = null,
        private int $timeout = 300,
    ) {}

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $args = $this->buildArgs($messages, $options, $tools);
        $process = $this->spawn($args, $options['cwd'] ?? null);
        $model = $options['model'] ?? 'claude-code-cli';
        $logContext = is_array($options['log_context'] ?? null) ? $options['log_context'] : [];
        $startedAt = microtime(true);

        $process->run();

        if (! $process->isSuccessful()) {
            PipelineLogger::aiError(
                'claude_code_cli',
                $model,
                $process->getExitCode(),
                $process->getErrorOutput(),
                array_merge($logContext, ['duration_ms' => self::elapsedMs($startedAt)]),
            );

            throw new \RuntimeException(
                "Claude Code CLI failed (exit {$process->getExitCode()}): {$process->getErrorOutput()}"
            );
        }

        $normalized = $this->parseStreamJsonOutput($process->getOutput(), $tools);

        PipelineLogger::aiCall(
            'claude_code_cli',
            $model,
            $normalized['usage'],
            array_merge($logContext, ['duration_ms' => self::elapsedMs($startedAt)]),
        );

        return $normalized;
    }

    private static function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    public function stream(array $messages, array $tools = [], array $options = []): \Generator
    {
        $args = $this->buildArgs($messages, $options, $tools);
        $process = $this->spawn($args, $options['cwd'] ?? null);
        $process->start();

        $buffer = '';
        foreach ($process as $type => $data) {
            if ($type !== Process::OUT) {
                continue;
            }

            $buffer .= $data;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $event = json_decode($line, true);
                if (! $event) {
                    continue;
                }
                yield $this->normalizeEvent($event);
            }
        }

        $process->wait();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                "Claude Code CLI failed (exit {$process->getExitCode()}): {$process->getErrorOutput()}"
            );
        }
    }

    private function buildArgs(array $messages, array $options, array $tools = []): array
    {
        $args = $this->splitCommand($this->command);

        if (isset($options['model'])) {
            $args[] = '--model';
            $args[] = $options['model'];
        }

        foreach ($options['allowedTools'] ?? [] as $tool) {
            $args[] = '--allowedTools';
            $args[] = $tool;
        }

        $args[] = '--';
        $args[] = $this->buildPrompt($messages, $tools);

        return $args;
    }

    private function spawn(array $args, ?string $cwd = null): Process
    {
        $process = new Process($args);
        $process->setTimeout($this->timeout);

        $dir = $cwd ?? $this->workingDirectory;
        if ($dir) {
            $process->setWorkingDirectory($dir);
        }

        return $process;
    }

    private function splitCommand(string $command): array
    {
        return preg_split('/\s+/', trim($command)) ?: ['claude'];
    }

    private function buildPrompt(array $messages, array $tools = []): string
    {
        $parts = [];
        foreach ($messages as $msg) {
            $parts[] = $msg['content'];
        }

        $terminal = $this->pickTerminalTool($tools);
        if ($terminal !== null) {
            $schema = json_encode($terminal['parameters'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $parts[] = <<<PROMPT
# Response Format

Use your built-in tools (Read, Write, Edit, Bash, Grep, Glob) to
accomplish the task. All file operations are already scoped to the
worktree via the working directory.

When — and only when — the task is fully complete, respond with
**ONLY** a single JSON object matching the schema below. No prose,
no markdown fences, no explanation outside the JSON.

The JSON you emit will be delivered to the orchestrator as a
`{$terminal['name']}` tool call.

Schema for `{$terminal['name']}`:

{$schema}
PROMPT;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Accumulate the NDJSON stream into a single response matching the
     * AiProvider::chat() contract. Final assistant text lands in content;
     * built-in tool_use blocks (Read/Write/Bash/etc.) land in tool_calls.
     *
     * When agent tools are passed, the model is prompted to respond with
     * raw JSON matching one tool's schema — parse that text and synthesize
     * a matching tool_calls entry so PreflightAgent et al. can consume it.
     */
    private function parseStreamJsonOutput(string $output, array $tools = []): array
    {
        $text = '';
        $toolCalls = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $raw = [];

        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            $raw[] = $event;

            $type = $event['type'] ?? null;

            if ($type === 'assistant' && isset($event['message']['content']) && is_array($event['message']['content'])) {
                foreach ($event['message']['content'] as $block) {
                    if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                        $text .= $block['text'];
                    } elseif (($block['type'] ?? null) === 'tool_use') {
                        $toolCalls[] = [
                            'id' => $block['id'] ?? '',
                            'name' => $block['name'] ?? '',
                            'arguments' => $block['input'] ?? [],
                        ];
                    }
                }
            }

            if ($type === 'result') {
                if ($text === '' && isset($event['result'])) {
                    $text = is_string($event['result']) ? $event['result'] : json_encode($event['result']);
                }
                $usage = [
                    'input_tokens' => $event['usage']['input_tokens'] ?? 0,
                    'output_tokens' => $event['usage']['output_tokens'] ?? 0,
                ];
            }
        }

        $terminal = $this->pickTerminalTool($tools);
        if ($terminal !== null && $text !== '') {
            $synthetic = $this->synthesizeToolCall($text, $terminal);
            if ($synthetic !== null) {
                $toolCalls[] = $synthetic;
            }
        }

        return [
            'content' => $text,
            'tool_calls' => $toolCalls,
            'usage' => $usage,
            'raw' => $raw,
        ];
    }

    /**
     * Pick the tool the CLI should target with its JSON response.
     *
     * For single-tool flows (preflight) that's the one tool. For multi-tool
     * flows (implement/verify/release) the iterative tool surface is meant
     * for OpenAI-style function calling which Claude Code doesn't do —
     * instead we locate the terminal "done" tool by the `_complete` suffix
     * and map the model's final JSON back to that one. Claude Code uses
     * its own built-in Read/Write/Bash etc. to do the actual work.
     */
    private function pickTerminalTool(array $tools): ?array
    {
        if (empty($tools)) {
            return null;
        }

        if (count($tools) === 1) {
            return $tools[0];
        }

        foreach ($tools as $tool) {
            if (str_ends_with($tool['name'] ?? '', '_complete')) {
                return $tool;
            }
        }

        return null;
    }

    private function synthesizeToolCall(string $text, array $tool): ?array
    {
        $json = $this->extractJson($text);
        if ($json === null) {
            return null;
        }

        return [
            'id' => 'synth-'.uniqid(),
            'name' => $tool['name'] ?? '',
            'arguments' => $json,
        ];
    }

    /**
     * Pull a JSON object out of arbitrary text. Handles the common shapes:
     * bare JSON, fenced ```json blocks, or a JSON object embedded in prose.
     */
    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        // Strip fenced code block if present.
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
            $text = $m[1];
        }

        // Find first { and matching }.
        $first = strpos($text, '{');
        $last = strrpos($text, '}');
        if ($first === false || $last === false || $last <= $first) {
            return null;
        }

        $candidate = substr($text, $first, $last - $first + 1);
        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeEvent(array $event): array
    {
        return [
            'type' => match ($event['type'] ?? null) {
                'assistant' => 'content',
                'result' => 'done',
                default => 'other',
            },
            'content' => $event['message']['content'][0]['text'] ?? ($event['result'] ?? null),
            'tool_calls' => null,
            'usage' => null,
        ];
    }
}
