<?php

namespace App\Services\AiProviders;

use App\Contracts\AiProvider;
use App\Enums\StageName;
use App\Models\ProviderConfig;

class AiProviderManager
{
    public function resolve(?string $workspaceId = null, ?StageName $stage = null): AiProvider
    {
        $config = $this->resolveConfig($workspaceId, $stage);

        $provider = $config?->provider ?? config('ai.default');
        $settings = $config?->settings ?? [];

        return $this->make($provider, $settings);
    }

    public function make(string $provider, array $settings = []): AiProvider
    {
        $providerConfig = config("ai.providers.{$provider}", []);
        $merged = array_merge($providerConfig, $settings);

        return match ($provider) {
            'anthropic' => new AnthropicProvider(
                apiKey: $merged['api_key'] ?? '',
                model: $merged['model'] ?? 'claude-sonnet-4-6',
                baseUrl: $merged['base_url'] ?? 'https://api.anthropic.com',
            ),
            'openai' => new OpenAiProvider(
                apiKey: $merged['api_key'] ?? '',
                model: $merged['model'] ?? 'gpt-4o',
                baseUrl: $merged['base_url'] ?? 'https://api.openai.com',
            ),
            'gemini' => new GeminiProvider(
                apiKey: $merged['api_key'] ?? '',
                model: $merged['model'] ?? 'gemini-2.5-flash',
                baseUrl: $merged['base_url'] ?? 'https://generativelanguage.googleapis.com',
            ),
            'claude_code_cli' => new ClaudeCodeCliProvider(
                command: $merged['command'] ?? 'claude --dangerously-skip-permissions --print --output-format stream-json --verbose',
                workingDirectory: $merged['working_directory'] ?? null,
                timeout: $merged['timeout'] ?? 300,
            ),
            default => throw new \InvalidArgumentException("Unknown AI provider: {$provider}"),
        };
    }

    private function resolveConfig(?string $workspaceId, ?StageName $stage): ?ProviderConfig
    {
        if ($workspaceId && $stage) {
            $config = ProviderConfig::where('scope', 'workspace')
                ->where('scope_id', $workspaceId)
                ->where('stage', $stage->value)
                ->first();

            if ($config) {
                return $config;
            }
        }

        if ($workspaceId) {
            $config = ProviderConfig::where('scope', 'workspace')
                ->where('scope_id', $workspaceId)
                ->whereNull('stage')
                ->first();

            if ($config) {
                return $config;
            }
        }

        if ($stage) {
            $config = ProviderConfig::where('scope', 'global')
                ->whereNull('scope_id')
                ->where('stage', $stage->value)
                ->first();

            if ($config) {
                return $config;
            }
        }

        return ProviderConfig::where('scope', 'global')
            ->whereNull('scope_id')
            ->whereNull('stage')
            ->first();
    }
}
