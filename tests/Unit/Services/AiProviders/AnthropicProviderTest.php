<?php

namespace Tests\Unit\Services\AiProviders;

use App\Contracts\AiProvider;
use App\Services\AiProviders\AnthropicProvider;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit coverage for AnthropicProvider.
 *
 * Bootstraps just enough of the Http facade (a Factory bound to a throwaway
 * Container) so we can use Http::fake without booting the full Laravel app.
 * Feature tests/Feature/AiProviderTest.php exercises the same shapes inside a
 * full app boot — the duplication is intentional: this file gates regressions
 * during fast Unit-suite runs.
 */
class AnthropicProviderTest extends TestCase
{
    private static Container $container;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$container = new Container();
        Facade::setFacadeApplication(self::$container);
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Fresh Factory each test → no fake state leaks.
        self::$container->instance(HttpFactory::class, new HttpFactory());
        Facade::clearResolvedInstance(HttpFactory::class);
    }

    public function test_implements_ai_provider_contract(): void
    {
        $this->assertInstanceOf(AiProvider::class, new AnthropicProvider('k'));
    }

    // --- Happy path: chat() shape ---

    public function test_chat_returns_normalized_text_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Hi there!']],
                'usage' => ['input_tokens' => 7, 'output_tokens' => 4],
            ]),
        ]);

        $result = (new AnthropicProvider('test-key'))
            ->chat([['role' => 'user', 'content' => 'Hello']]);

        $this->assertSame('Hi there!', $result['content']);
        $this->assertSame([], $result['tool_calls']);
        $this->assertSame(7, $result['usage']['input_tokens']);
        $this->assertSame(4, $result['usage']['output_tokens']);
        $this->assertArrayHasKey('raw', $result);
    }

    public function test_chat_concatenates_multiple_text_blocks(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Part one. '],
                    ['type' => 'text', 'text' => 'Part two.'],
                ],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 2],
            ]),
        ]);

        $result = (new AnthropicProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Part one. Part two.', $result['content']);
    }

    public function test_chat_extracts_tool_use_blocks(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Looking up...'],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_42',
                        'name' => 'get_weather',
                        'input' => ['city' => 'Paris'],
                    ],
                ],
                'usage' => ['input_tokens' => 30, 'output_tokens' => 12],
            ]),
        ]);

        $result = (new AnthropicProvider('k'))->chat(
            [['role' => 'user', 'content' => 'weather?']],
            [['name' => 'get_weather', 'description' => 'w', 'parameters' => ['type' => 'object']]],
        );

        $this->assertSame('Looking up...', $result['content']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame([
            'id' => 'toolu_42',
            'name' => 'get_weather',
            'arguments' => ['city' => 'Paris'],
        ], $result['tool_calls'][0]);
    }

    public function test_chat_handles_missing_usage_gracefully(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'x']],
            ]),
        ]);

        $result = (new AnthropicProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame(['input_tokens' => 0, 'output_tokens' => 0], $result['usage']);
    }

    public function test_chat_returns_null_content_when_only_tool_use(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'f', 'input' => []]],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
        ]);

        $result = (new AnthropicProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertNull($result['content']);
        $this->assertCount(1, $result['tool_calls']);
    }

    public function test_tool_with_empty_input_decodes_to_empty_array(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'noop']],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ]),
        ]);

        $result = (new AnthropicProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame([], $result['tool_calls'][0]['arguments']);
    }

    // --- Request shaping ---

    public function test_chat_extracts_system_message_into_top_level_field(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [], 'usage' => []]),
        ]);

        (new AnthropicProvider('k'))->chat([
            ['role' => 'system', 'content' => 'Be terse.'],
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['system'] === 'Be terse.'
                && count($body['messages']) === 1
                && $body['messages'][0]['role'] === 'user';
        });
    }

    public function test_chat_sends_required_anthropic_headers(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [], 'usage' => []]),
        ]);

        (new AnthropicProvider('secret-key'))->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => $req->hasHeader('x-api-key', 'secret-key')
            && $req->hasHeader('anthropic-version', '2023-06-01'));
    }

    public function test_chat_uses_constructor_model_when_no_options_override(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [], 'usage' => []]),
        ]);

        (new AnthropicProvider('k', 'claude-opus-4-6'))
            ->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => $req->data()['model'] === 'claude-opus-4-6'
            && $req->data()['max_tokens'] === 4096);
    }

    public function test_chat_options_override_model_and_max_tokens(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [], 'usage' => []]),
        ]);

        (new AnthropicProvider('k', 'default-model'))
            ->chat(
                [['role' => 'user', 'content' => 'hi']],
                [],
                ['model' => 'override-model', 'max_tokens' => 100],
            );

        Http::assertSent(fn ($req) => $req->data()['model'] === 'override-model'
            && $req->data()['max_tokens'] === 100);
    }

    public function test_chat_shapes_tools_for_anthropic_schema(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [], 'usage' => []]),
        ]);

        (new AnthropicProvider('k'))->chat(
            [['role' => 'user', 'content' => 'hi']],
            [[
                'name' => 'do_thing',
                'description' => 'does it',
                'parameters' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            ]],
        );

        Http::assertSent(function ($req) {
            $tool = $req->data()['tools'][0];

            return $tool['name'] === 'do_thing'
                && $tool['description'] === 'does it'
                && $tool['input_schema']['type'] === 'object'
                && isset($tool['input_schema']['properties']['x']);
        });
    }

    public function test_chat_omits_tools_field_when_no_tools_passed(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [], 'usage' => []]),
        ]);

        (new AnthropicProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => ! array_key_exists('tools', $req->data()));
    }

    public function test_chat_omits_system_field_when_no_system_message(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [], 'usage' => []]),
        ]);

        (new AnthropicProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => ! array_key_exists('system', $req->data()));
    }

    public function test_chat_uses_constructor_base_url(): void
    {
        Http::fake([
            'proxy.example.test/*' => Http::response(['content' => [], 'usage' => []]),
        ]);

        (new AnthropicProvider('k', 'm', 'https://proxy.example.test'))
            ->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://proxy.example.test/v1/messages'));
    }

    // --- Error handling ---

    public function test_chat_throws_request_exception_on_4xx(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['type' => 'rate_limit_error']], 429),
        ]);

        $this->expectException(RequestException::class);

        (new AnthropicProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function test_chat_throws_on_5xx(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'down'], 500),
        ]);

        $this->expectException(RequestException::class);

        (new AnthropicProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function test_chat_handles_malformed_response_without_throwing(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['unexpected' => 'shape']),
        ]);

        $result = (new AnthropicProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertNull($result['content']);
        $this->assertSame([], $result['tool_calls']);
        $this->assertSame(['input_tokens' => 0, 'output_tokens' => 0], $result['usage']);
    }
}
