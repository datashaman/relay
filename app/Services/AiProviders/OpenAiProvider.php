<?php

namespace App\Services\AiProviders;

use App\Contracts\AiProvider;
use App\Support\Logging\PipelineLogger;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements AiProvider
{
    public function __construct(
        private string $apiKey,
        private string $model = 'gpt-4o',
        private string $baseUrl = 'https://api.openai.com',
    ) {}

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $body = $this->buildRequestBody($messages, $tools, $options);
        $model = $body['model'];
        $logContext = is_array($options['log_context'] ?? null) ? $options['log_context'] : [];
        $startedAt = microtime(true);

        try {
            $response = Http::withToken($this->apiKey)
                ->post("{$this->baseUrl}/v1/chat/completions", $body);

            $response->throw();
        } catch (RequestException $e) {
            PipelineLogger::aiError(
                'openai',
                $model,
                $e->response->status(),
                $e->response->body(),
                array_merge($logContext, ['duration_ms' => self::elapsedMs($startedAt)]),
            );
            throw $e;
        }

        $normalized = $this->normalizeResponse($response->json());

        PipelineLogger::aiCall(
            'openai',
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
        $body = $this->buildRequestBody($messages, $tools, $options);
        $body['stream'] = true;

        $response = Http::withToken($this->apiKey)
            ->withOptions(['stream' => true])
            ->post("{$this->baseUrl}/v1/chat/completions", $body);

        $response->throw();

        foreach (explode("\n", $response->body()) as $line) {
            if (! str_starts_with($line, 'data: ') || trim($line) === 'data: [DONE]') {
                continue;
            }

            $data = json_decode(substr($line, 6), true);
            if (! $data) {
                continue;
            }

            $delta = $data['choices'][0]['delta'] ?? [];

            yield [
                'type' => isset($delta['content']) ? 'content' : 'other',
                'content' => $delta['content'] ?? null,
                'tool_calls' => null,
                'usage' => $data['usage'] ?? null,
            ];
        }
    }

    private function buildRequestBody(array $messages, array $tools, array $options): array
    {
        $body = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
        ];

        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }

        if (! empty($tools)) {
            $body['tools'] = array_map(fn (array $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass],
                ],
            ], $tools);
        }

        return $body;
    }

    private function normalizeResponse(array $data): array
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = [
                'id' => $tc['id'],
                'name' => $tc['function']['name'],
                'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
            ];
        }

        return [
            'content' => $message['content'] ?? null,
            'tool_calls' => $toolCalls,
            'usage' => [
                'input_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                'output_tokens' => $data['usage']['completion_tokens'] ?? 0,
            ],
            'raw' => $data,
        ];
    }
}
