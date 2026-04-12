<?php

namespace App\Contracts;

interface AiProvider
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $options
     * @return array{content: ?string, tool_calls: array<int, array{id: string, name: string, arguments: array<string, mixed>}>, usage: array{input_tokens: int, output_tokens: int}, raw: array<string, mixed>}
     */
    public function chat(array $messages, array $tools = [], array $options = []): array;

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $options
     * @return \Generator<int, array{type: string, content: ?string, tool_calls: ?array, usage: ?array}>
     */
    public function stream(array $messages, array $tools = [], array $options = []): \Generator;
}
