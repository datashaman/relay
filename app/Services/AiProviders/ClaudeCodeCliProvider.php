<?php

namespace App\Services\AiProviders;

use App\Contracts\AiProvider;
use Symfony\Component\Process\Process;

class ClaudeCodeCliProvider implements AiProvider
{
    public function __construct(
        private string $command = 'claude --dangerously-skip-permissions --print --output-format stream-json',
        private ?string $workingDirectory = null,
        private int $timeout = 300,
    ) {}

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $args = $this->buildArgs($messages, $options);
        $process = $this->spawn($args);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                "Claude Code CLI failed (exit {$process->getExitCode()}): {$process->getErrorOutput()}"
            );
        }

        return $this->parseStreamJsonOutput($process->getOutput());
    }

    public function stream(array $messages, array $tools = [], array $options = []): \Generator
    {
        $args = $this->buildArgs($messages, $options);
        $process = $this->spawn($args);
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

    private function buildArgs(array $messages, array $options): array
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
        $args[] = $this->buildPrompt($messages);

        return $args;
    }

    private function spawn(array $args): Process
    {
        $process = new Process($args);
        $process->setTimeout($this->timeout);

        if ($this->workingDirectory) {
            $process->setWorkingDirectory($this->workingDirectory);
        }

        return $process;
    }

    private function splitCommand(string $command): array
    {
        return preg_split('/\s+/', trim($command)) ?: ['claude'];
    }

    private function buildPrompt(array $messages): string
    {
        $parts = [];
        foreach ($messages as $msg) {
            $parts[] = $msg['content'];
        }

        return implode("\n\n", $parts);
    }

    /**
     * Accumulate the NDJSON stream into a single response matching the
     * AiProvider::chat() contract. Final assistant text lands in content;
     * tool_use content blocks land in tool_calls.
     */
    private function parseStreamJsonOutput(string $output): array
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

        return [
            'content' => $text,
            'tool_calls' => $toolCalls,
            'usage' => $usage,
            'raw' => $raw,
        ];
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
