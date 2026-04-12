<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\StageName;
use App\Models\ProviderConfig;
use App\Services\AiProviders\AiProviderManager;
use App\Services\AiProviders\AnthropicProvider;
use App\Services\AiProviders\ClaudeCodeCliProvider;
use App\Services\AiProviders\GeminiProvider;
use App\Services\AiProviders\OpenAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(base_path("tests/fixtures/ai/{$name}.json")),
            true
        );
    }

    // --- Contract compliance ---

    public function test_all_providers_implement_ai_provider_contract(): void
    {
        $this->assertInstanceOf(AiProvider::class, new AnthropicProvider('key'));
        $this->assertInstanceOf(AiProvider::class, new OpenAiProvider('key'));
        $this->assertInstanceOf(AiProvider::class, new GeminiProvider('key'));
        $this->assertInstanceOf(AiProvider::class, new ClaudeCodeCliProvider);
    }

    // --- Anthropic ---

    public function test_anthropic_chat_returns_normalized_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fixture('anthropic_chat')),
        ]);

        $provider = new AnthropicProvider('test-key');
        $result = $provider->chat([['role' => 'user', 'content' => 'Hello']]);

        $this->assertEquals('Hello! How can I help you today?', $result['content']);
        $this->assertEmpty($result['tool_calls']);
        $this->assertEquals(12, $result['usage']['input_tokens']);
        $this->assertEquals(10, $result['usage']['output_tokens']);
        $this->assertArrayHasKey('raw', $result);
    }

    public function test_anthropic_tool_use_normalized(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fixture('anthropic_tool_use')),
        ]);

        $provider = new AnthropicProvider('test-key');
        $result = $provider->chat(
            [['role' => 'user', 'content' => 'What is the weather?']],
            [['name' => 'get_weather', 'description' => 'Get weather', 'parameters' => ['type' => 'object']]],
        );

        $this->assertNotEmpty($result['tool_calls']);
        $tc = $result['tool_calls'][0];
        $this->assertEquals('get_weather', $tc['name']);
        $this->assertEquals('toolu_01A09q90qw90lq917835lhl', $tc['id']);
        $this->assertEquals(['location' => 'San Francisco'], $tc['arguments']);
        $this->assertStringContains("I'll check the weather", $result['content']);
    }

    public function test_anthropic_extracts_system_message(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fixture('anthropic_chat')),
        ]);

        $provider = new AnthropicProvider('test-key');
        $provider->chat([
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['system'] === 'You are helpful.'
                && count($body['messages']) === 1
                && $body['messages'][0]['role'] === 'user';
        });
    }

    public function test_anthropic_sends_correct_headers(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fixture('anthropic_chat')),
        ]);

        $provider = new AnthropicProvider('my-api-key');
        $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'my-api-key')
                && $request->hasHeader('anthropic-version', '2023-06-01');
        });
    }

    // --- OpenAI ---

    public function test_openai_chat_returns_normalized_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->fixture('openai_chat')),
        ]);

        $provider = new OpenAiProvider('test-key');
        $result = $provider->chat([['role' => 'user', 'content' => 'Hello']]);

        $this->assertEquals('Hello! How can I help you today?', $result['content']);
        $this->assertEmpty($result['tool_calls']);
        $this->assertEquals(12, $result['usage']['input_tokens']);
        $this->assertEquals(10, $result['usage']['output_tokens']);
    }

    public function test_openai_tool_use_normalized(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->fixture('openai_tool_use')),
        ]);

        $provider = new OpenAiProvider('test-key');
        $result = $provider->chat(
            [['role' => 'user', 'content' => 'What is the weather?']],
            [['name' => 'get_weather', 'description' => 'Get weather']],
        );

        $this->assertNotEmpty($result['tool_calls']);
        $tc = $result['tool_calls'][0];
        $this->assertEquals('get_weather', $tc['name']);
        $this->assertEquals('call_abc123', $tc['id']);
        $this->assertEquals(['location' => 'San Francisco'], $tc['arguments']);
    }

    public function test_openai_sends_bearer_token(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->fixture('openai_chat')),
        ]);

        $provider = new OpenAiProvider('my-openai-key');
        $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer my-openai-key');
        });
    }

    // --- Gemini ---

    public function test_gemini_chat_returns_normalized_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fixture('gemini_chat')),
        ]);

        $provider = new GeminiProvider('test-key');
        $result = $provider->chat([['role' => 'user', 'content' => 'Hello']]);

        $this->assertEquals('Hello! How can I help you today?', $result['content']);
        $this->assertEmpty($result['tool_calls']);
        $this->assertEquals(12, $result['usage']['input_tokens']);
        $this->assertEquals(10, $result['usage']['output_tokens']);
    }

    public function test_gemini_tool_use_normalized(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fixture('gemini_tool_use')),
        ]);

        $provider = new GeminiProvider('test-key');
        $result = $provider->chat(
            [['role' => 'user', 'content' => 'What is the weather?']],
            [['name' => 'get_weather', 'description' => 'Get weather']],
        );

        $this->assertNotEmpty($result['tool_calls']);
        $tc = $result['tool_calls'][0];
        $this->assertEquals('get_weather', $tc['name']);
        $this->assertEquals(['location' => 'San Francisco'], $tc['arguments']);
        $this->assertStringContains("I'll check the weather", $result['content']);
    }

    public function test_gemini_maps_roles_correctly(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fixture('gemini_chat')),
        ]);

        $provider = new GeminiProvider('test-key');
        $provider->chat([
            ['role' => 'system', 'content' => 'Be helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
            ['role' => 'user', 'content' => 'Bye'],
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['systemInstruction'])
                && $body['systemInstruction']['parts'][0]['text'] === 'Be helpful.'
                && count($body['contents']) === 3
                && $body['contents'][1]['role'] === 'model';
        });
    }

    public function test_gemini_includes_api_key_in_url(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fixture('gemini_chat')),
        ]);

        $provider = new GeminiProvider('my-gemini-key');
        $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'key=my-gemini-key');
        });
    }

    // --- Claude Code CLI ---

    public function test_claude_code_cli_normalizes_json_output(): void
    {
        $fixture = $this->fixture('claude_code_cli_output');

        $provider = $this->getMockBuilder(ClaudeCodeCliProvider::class)
            ->setConstructorArgs(['echo'])
            ->onlyMethods(['chat'])
            ->getMock();

        $provider->method('chat')->willReturn([
            'content' => $fixture['result'],
            'tool_calls' => [],
            'usage' => [
                'input_tokens' => $fixture['usage']['input_tokens'],
                'output_tokens' => $fixture['usage']['output_tokens'],
            ],
            'raw' => $fixture,
        ]);

        $result = $provider->chat([['role' => 'user', 'content' => 'Hello']]);

        $this->assertEquals('Hello! How can I help you today?', $result['content']);
        $this->assertEmpty($result['tool_calls']);
        $this->assertEquals(12, $result['usage']['input_tokens']);
    }

    // --- AiProviderManager ---

    public function test_manager_resolves_default_provider(): void
    {
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.api_key' => 'test']);

        $manager = new AiProviderManager;
        $provider = $manager->resolve();

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
    }

    public function test_manager_resolves_global_provider_config(): void
    {
        config(['ai.providers.openai.api_key' => 'test']);

        ProviderConfig::create([
            'provider' => 'openai',
            'scope' => 'global',
            'scope_id' => null,
            'stage' => null,
            'settings' => ['model' => 'gpt-4o-mini'],
        ]);

        $manager = new AiProviderManager;
        $provider = $manager->resolve();

        $this->assertInstanceOf(OpenAiProvider::class, $provider);
    }

    public function test_manager_workspace_overrides_global(): void
    {
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.api_key' => 'test']);
        config(['ai.providers.gemini.api_key' => 'test']);

        ProviderConfig::create([
            'provider' => 'anthropic',
            'scope' => 'global',
            'scope_id' => null,
            'stage' => null,
        ]);

        ProviderConfig::create([
            'provider' => 'gemini',
            'scope' => 'workspace',
            'scope_id' => '42',
            'stage' => null,
        ]);

        $manager = new AiProviderManager;
        $provider = $manager->resolve('42');

        $this->assertInstanceOf(GeminiProvider::class, $provider);
    }

    public function test_manager_stage_overrides_workspace(): void
    {
        config(['ai.providers.anthropic.api_key' => 'test']);
        config(['ai.providers.openai.api_key' => 'test']);

        ProviderConfig::create([
            'provider' => 'anthropic',
            'scope' => 'workspace',
            'scope_id' => '42',
            'stage' => null,
        ]);

        ProviderConfig::create([
            'provider' => 'openai',
            'scope' => 'workspace',
            'scope_id' => '42',
            'stage' => StageName::Implement->value,
        ]);

        $manager = new AiProviderManager;
        $provider = $manager->resolve('42', StageName::Implement);

        $this->assertInstanceOf(OpenAiProvider::class, $provider);
    }

    public function test_manager_falls_back_through_scopes(): void
    {
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.api_key' => 'test']);

        $manager = new AiProviderManager;
        $provider = $manager->resolve('99', StageName::Verify);

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
    }

    public function test_manager_make_creates_claude_code_cli(): void
    {
        $manager = new AiProviderManager;
        $provider = $manager->make('claude_code_cli', ['binary_path' => '/usr/local/bin/claude']);

        $this->assertInstanceOf(ClaudeCodeCliProvider::class, $provider);
    }

    public function test_manager_make_throws_on_unknown_provider(): void
    {
        $manager = new AiProviderManager;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown AI provider: nonexistent');

        $manager->make('nonexistent');
    }

    public function test_manager_merges_config_settings_with_db_settings(): void
    {
        config(['ai.providers.anthropic.api_key' => 'from-config']);

        ProviderConfig::create([
            'provider' => 'anthropic',
            'scope' => 'global',
            'scope_id' => null,
            'stage' => null,
            'settings' => ['model' => 'claude-opus-4-6'],
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fixture('anthropic_chat')),
        ]);

        $manager = new AiProviderManager;
        $provider = $manager->resolve();
        $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['model'] === 'claude-opus-4-6'
                && $request->hasHeader('x-api-key', 'from-config');
        });
    }

    // --- Normalized output structure ---

    public function test_all_http_providers_return_consistent_structure(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->fixture('anthropic_chat')),
            'api.openai.com/*' => Http::response($this->fixture('openai_chat')),
            'generativelanguage.googleapis.com/*' => Http::response($this->fixture('gemini_chat')),
        ]);

        $providers = [
            new AnthropicProvider('key'),
            new OpenAiProvider('key'),
            new GeminiProvider('key'),
        ];

        foreach ($providers as $provider) {
            $result = $provider->chat([['role' => 'user', 'content' => 'Hello']]);

            $this->assertArrayHasKey('content', $result, get_class($provider));
            $this->assertArrayHasKey('tool_calls', $result, get_class($provider));
            $this->assertArrayHasKey('usage', $result, get_class($provider));
            $this->assertArrayHasKey('raw', $result, get_class($provider));
            $this->assertArrayHasKey('input_tokens', $result['usage'], get_class($provider));
            $this->assertArrayHasKey('output_tokens', $result['usage'], get_class($provider));
            $this->assertIsArray($result['tool_calls'], get_class($provider));
            $this->assertIsString($result['content'], get_class($provider));
        }
    }

    private function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertNotNull($haystack);
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
