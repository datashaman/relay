<?php

namespace Tests\Unit\Services\AiProviders;

use App\Contracts\AiProvider;
use App\Services\AiProviders\OpenAiProvider;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit coverage for OpenAiProvider.
 *
 * Bootstraps a minimal Http facade (Factory bound to a throwaway Container)
 * so Http::fake works without booting Laravel. Feature tests/Feature/AiProviderTest.php
 * covers overlapping behaviour inside a full app boot.
 */
class OpenAiProviderTest extends TestCase
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
        self::$container->instance(HttpFactory::class, new HttpFactory());
        Facade::clearResolvedInstance(HttpFactory::class);
    }

    public function test_implements_ai_provider_contract(): void
    {
        $this->assertInstanceOf(AiProvider::class, new OpenAiProvider('k'));
    }

    // --- Happy path ---

    public function test_chat_returns_normalized_text_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-x',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Howdy!'],
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
            ]),
        ]);

        $result = (new OpenAiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Howdy!', $result['content']);
        $this->assertSame([], $result['tool_calls']);
        $this->assertSame(5, $result['usage']['input_tokens']);
        $this->assertSame(3, $result['usage']['output_tokens']);
        $this->assertArrayHasKey('raw', $result);
    }

    public function test_chat_decodes_tool_call_arguments_from_json_string(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Calling tool',
                        'tool_calls' => [[
                            'id' => 'call_99',
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_weather',
                                'arguments' => '{"city":"Berlin","units":"c"}',
                            ],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2],
            ]),
        ]);

        $result = (new OpenAiProvider('k'))
            ->chat([['role' => 'user', 'content' => 'weather?']],
                [['name' => 'get_weather', 'description' => 'w']]);

        $this->assertSame('Calling tool', $result['content']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame([
            'id' => 'call_99',
            'name' => 'get_weather',
            'arguments' => ['city' => 'Berlin', 'units' => 'c'],
        ], $result['tool_calls'][0]);
    }

    public function test_chat_falls_back_to_empty_arguments_on_invalid_json(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'tool_calls' => [[
                            'id' => 'call_bad',
                            'function' => ['name' => 'noop', 'arguments' => 'not json'],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
            ]),
        ]);

        $result = (new OpenAiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame([], $result['tool_calls'][0]['arguments']);
    }

    public function test_chat_handles_missing_choices_without_throwing(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['unexpected' => true]),
        ]);

        $result = (new OpenAiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertNull($result['content']);
        $this->assertSame([], $result['tool_calls']);
        $this->assertSame(['input_tokens' => 0, 'output_tokens' => 0], $result['usage']);
    }

    // --- Request shaping ---

    public function test_chat_passes_messages_through_unchanged(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [], 'usage' => []]),
        ]);

        $messages = [
            ['role' => 'system', 'content' => 'be helpful'],
            ['role' => 'user', 'content' => 'hi'],
        ];

        (new OpenAiProvider('k'))->chat($messages);

        Http::assertSent(fn ($req) => $req->data()['messages'] === $messages);
    }

    public function test_chat_sends_bearer_token(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [], 'usage' => []]),
        ]);

        (new OpenAiProvider('sk-test'))->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_chat_uses_constructor_model(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [], 'usage' => []]),
        ]);

        (new OpenAiProvider('k', 'gpt-4o-mini'))
            ->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => $req->data()['model'] === 'gpt-4o-mini');
    }

    public function test_chat_options_override_model(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [], 'usage' => []]),
        ]);

        (new OpenAiProvider('k', 'default'))
            ->chat([['role' => 'user', 'content' => 'hi']], [], ['model' => 'override']);

        Http::assertSent(fn ($req) => $req->data()['model'] === 'override');
    }

    public function test_chat_omits_max_tokens_when_not_specified(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [], 'usage' => []]),
        ]);

        (new OpenAiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => ! array_key_exists('max_tokens', $req->data()));
    }

    public function test_chat_includes_max_tokens_when_provided(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [], 'usage' => []]),
        ]);

        (new OpenAiProvider('k'))->chat(
            [['role' => 'user', 'content' => 'hi']],
            [],
            ['max_tokens' => 200],
        );

        Http::assertSent(fn ($req) => $req->data()['max_tokens'] === 200);
    }

    public function test_chat_shapes_tools_for_function_calling(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [], 'usage' => []]),
        ]);

        (new OpenAiProvider('k'))->chat(
            [['role' => 'user', 'content' => 'hi']],
            [['name' => 'do_thing', 'description' => 'd', 'parameters' => ['type' => 'object']]],
        );

        Http::assertSent(function ($req) {
            $tool = $req->data()['tools'][0];

            return $tool['type'] === 'function'
                && $tool['function']['name'] === 'do_thing'
                && $tool['function']['description'] === 'd'
                && $tool['function']['parameters']['type'] === 'object';
        });
    }

    public function test_chat_omits_tools_when_none_passed(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [], 'usage' => []]),
        ]);

        (new OpenAiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => ! array_key_exists('tools', $req->data()));
    }

    public function test_chat_uses_constructor_base_url(): void
    {
        Http::fake([
            'azure.example.test/*' => Http::response(['choices' => [], 'usage' => []]),
        ]);

        (new OpenAiProvider('k', 'm', 'https://azure.example.test'))
            ->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://azure.example.test/v1/chat/completions'));
    }

    // --- Error handling ---

    public function test_chat_throws_on_429(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['code' => 'rate_limit']], 429),
        ]);

        $this->expectException(RequestException::class);

        (new OpenAiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function test_chat_throws_on_5xx(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => 'oops'], 503),
        ]);

        $this->expectException(RequestException::class);

        (new OpenAiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);
    }
}
