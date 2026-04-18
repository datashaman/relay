<?php

namespace App\Services\AiProviders;

use App\Contracts\AiProvider;
use App\Support\Logging\PipelineLogger;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements AiProvider
{
    public function __construct(
        private string $apiKey,
        private string $model = 'gemini-2.5-flash',
        private string $baseUrl = 'https://generativelanguage.googleapis.com',
    ) {}

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $model = $options['model'] ?? $this->model;
        $url = "{$this->baseUrl}/v1beta/models/{$model}:generateContent?key={$this->apiKey}";
        $logContext = is_array($options['log_context'] ?? null) ? $options['log_context'] : [];
        $startedAt = microtime(true);

        $body = $this->buildRequestBody($messages, $tools, $options);

        try {
            $response = Http::post($url, $body);
            $response->throw();
        } catch (RequestException $e) {
            PipelineLogger::aiError(
                'gemini',
                $model,
                $e->response->status(),
                $e->response->body(),
                array_merge($logContext, ['duration_ms' => self::elapsedMs($startedAt)]),
            );
            throw $e;
        }

        $normalized = $this->normalizeResponse($response->json());

        PipelineLogger::aiCall(
            'gemini',
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
        $model = $options['model'] ?? $this->model;
        $url = "{$this->baseUrl}/v1beta/models/{$model}:streamGenerateContent?key={$this->apiKey}&alt=sse";

        $body = $this->buildRequestBody($messages, $tools, $options);

        $response = Http::withOptions(['stream' => true])->post($url, $body);
        $response->throw();

        foreach (explode("\n", $response->body()) as $line) {
            if (! str_starts_with($line, 'data: ')) {
                continue;
            }

            $data = json_decode(substr($line, 6), true);
            if (! $data) {
                continue;
            }

            $part = $data['candidates'][0]['content']['parts'][0] ?? [];

            yield [
                'type' => isset($part['text']) ? 'content' : 'other',
                'content' => $part['text'] ?? null,
                'tool_calls' => null,
                'usage' => null,
            ];
        }
    }

    private function buildRequestBody(array $messages, array $tools, array $options): array
    {
        $systemInstruction = null;
        $contents = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemInstruction = $msg['content'];

                continue;
            }

            $contents[] = [
                'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['content']]],
            ];
        }

        $body = ['contents' => $contents];

        if ($systemInstruction !== null) {
            $body['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }

        if (! empty($tools)) {
            $body['tools'] = [[
                'functionDeclarations' => array_map(fn (array $tool) => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['parameters'] ?? ['type' => 'OBJECT', 'properties' => new \stdClass],
                ], $tools),
            ]];
        }

        if (isset($options['max_tokens'])) {
            $body['generationConfig'] = ['maxOutputTokens' => $options['max_tokens']];
        }

        return $body;
    }

    private function normalizeResponse(array $data): array
    {
        $content = null;
        $toolCalls = [];

        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $content = ($content ?? '').$part['text'];
            } elseif (isset($part['functionCall'])) {
                $toolCalls[] = [
                    'id' => 'gemini_'.uniqid(),
                    'name' => $part['functionCall']['name'],
                    'arguments' => $part['functionCall']['args'] ?? [],
                ];
            }
        }

        $usage = $data['usageMetadata'] ?? [];

        return [
            'content' => $content,
            'tool_calls' => $toolCalls,
            'usage' => [
                'input_tokens' => $usage['promptTokenCount'] ?? 0,
                'output_tokens' => $usage['candidatesTokenCount'] ?? 0,
            ],
            'raw' => $data,
        ];
    }
}
