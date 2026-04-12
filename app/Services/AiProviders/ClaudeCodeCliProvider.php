<?php

namespace App\Services\AiProviders;

use App\Contracts\AiProvider;
use Symfony\Component\Process\Process;

class ClaudeCodeCliProvider implements AiProvider
{
    public function __construct(
        private string $binaryPath = 'claude',
        private ?string $workingDirectory = null,
        private int $timeout = 300,
    ) {}

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $prompt = $this->buildPrompt($messages);

        $args = [$this->binaryPath, '--print', '--output-format', 'json'];

        if (isset($options['model'])) {
            $args[] = '--model';
            $args[] = $options['model'];
        }

        if (isset($options['max_tokens'])) {
            $args[] = '--max-tokens';
            $args[] = (string) $options['max_tokens'];
        }

        foreach ($options['allowedTools'] ?? [] as $tool) {
            $args[] = '--allowedTools';
            $args[] = $tool;
        }

        $args[] = '--';
        $args[] = $prompt;

        $process = new Process($args);
        $process->setTimeout($this->timeout);

        if ($this->workingDirectory) {
            $process->setWorkingDirectory($this->workingDirectory);
        }

        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                "Claude Code CLI failed (exit {$process->getExitCode()}): {$process->getErrorOutput()}"
            );
        }

        return $this->normalizeOutput($process->getOutput());
    }

    public function stream(array $messages, array $tools = [], array $options = []): \Generator
    {
        $prompt = $this->buildPrompt($messages);

        $args = [$this->binaryPath, '--output-format', 'stream-json'];

        if (isset($options['model'])) {
            $args[] = '--model';
            $args[] = $options['model'];
        }

        foreach ($options['allowedTools'] ?? [] as $tool) {
            $args[] = '--allowedTools';
            $args[] = $tool;
        }

        $args[] = '--';
        $args[] = $prompt;

        $process = new Process($args);
        $process->setTimeout($this->timeout);

        if ($this->workingDirectory) {
            $process->setWorkingDirectory($this->workingDirectory);
        }

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

                yield [
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

        $process->wait();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                "Claude Code CLI failed (exit {$process->getExitCode()}): {$process->getErrorOutput()}"
            );
        }
    }

    private function buildPrompt(array $messages): string
    {
        $parts = [];
        foreach ($messages as $msg) {
            $parts[] = $msg['content'];
        }

        return implode("\n\n", $parts);
    }

    private function normalizeOutput(string $output): array
    {
        $data = json_decode($output, true);

        if (is_array($data)) {
            $content = $data['result'] ?? $output;

            return [
                'content' => is_string($content) ? $content : json_encode($content),
                'tool_calls' => [],
                'usage' => [
                    'input_tokens' => $data['usage']['input_tokens'] ?? 0,
                    'output_tokens' => $data['usage']['output_tokens'] ?? 0,
                ],
                'raw' => $data,
            ];
        }

        return [
            'content' => $output,
            'tool_calls' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            'raw' => ['output' => $output],
        ];
    }
}
