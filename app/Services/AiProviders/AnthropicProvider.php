<?php

namespace App\Services\AiProviders;

use App\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements AiProvider
{
    public function __construct(
        private string $apiKey,
        private string $model = 'claude-sonnet-4-6',
        private string $baseUrl = 'https://api.anthropic.com',
    ) {}

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $body = $this->buildRequestBody($messages, $tools, $options);

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ])->post("{$this->baseUrl}/v1/messages", $body);

        $response->throw();

        return $this->normalizeResponse($response->json());
    }

    public function stream(array $messages, array $tools = [], array $options = []): \Generator
    {
        $body = $this->buildRequestBody($messages, $tools, $options);
        $body['stream'] = true;

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ])->withOptions(['stream' => true])
            ->post("{$this->baseUrl}/v1/messages", $body);

        $response->throw();

        $buffer = '';
        foreach (explode("\n", $response->body()) as $line) {
            if (! str_starts_with($line, 'data: ')) {
                continue;
            }

            $data = json_decode(substr($line, 6), true);
            if (! $data) {
                continue;
            }

            yield match ($data['type'] ?? null) {
                'content_block_delta' => [
                    'type' => 'content',
                    'content' => $data['delta']['text'] ?? null,
                    'tool_calls' => null,
                    'usage' => null,
                ],
                'message_stop' => [
                    'type' => 'done',
                    'content' => null,
                    'tool_calls' => null,
                    'usage' => $data['message']['usage'] ?? null,
                ],
                default => [
                    'type' => 'other',
                    'content' => null,
                    'tool_calls' => null,
                    'usage' => null,
                ],
            };
        }
    }

    private function buildRequestBody(array $messages, array $tools, array $options): array
    {
        $system = null;
        $filtered = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system = $msg['content'];
            } else {
                $filtered[] = $msg;
            }
        }

        $body = [
            'model' => $options['model'] ?? $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => $filtered,
        ];

        if ($system !== null) {
            $body['system'] = $system;
        }

        if (! empty($tools)) {
            $body['tools'] = array_map(fn (array $tool) => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'input_schema' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass],
            ], $tools);
        }

        return $body;
    }

    private function normalizeResponse(array $data): array
    {
        $content = null;
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content = ($content ?? '').$block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'arguments' => $block['input'] ?? [],
                ];
            }
        }

        return [
            'content' => $content,
            'tool_calls' => $toolCalls,
            'usage' => [
                'input_tokens' => $data['usage']['input_tokens'] ?? 0,
                'output_tokens' => $data['usage']['output_tokens'] ?? 0,
            ],
            'raw' => $data,
        ];
    }
}
