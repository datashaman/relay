<?php

namespace Tests\Unit\Services\AiProviders;

use App\Contracts\AiProvider;
use App\Services\AiProviders\GeminiProvider;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit coverage for GeminiProvider.
 *
 * Bootstraps a minimal Http facade (Factory bound to a throwaway Container)
 * so Http::fake works without booting Laravel. Feature tests/Feature/AiProviderTest.php
 * covers overlapping behaviour inside a full app boot.
 */
class GeminiProviderTest extends TestCase
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
        $this->assertInstanceOf(AiProvider::class, new GeminiProvider('k'));
    }

    // --- Happy path ---

    public function test_chat_returns_normalized_text_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Bonjour!']]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 9, 'candidatesTokenCount' => 6],
            ]),
        ]);

        $result = (new GeminiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Bonjour!', $result['content']);
        $this->assertSame([], $result['tool_calls']);
        $this->assertSame(9, $result['usage']['input_tokens']);
        $this->assertSame(6, $result['usage']['output_tokens']);
        $this->assertArrayHasKey('raw', $result);
    }

    public function test_chat_concatenates_text_parts(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [
                        ['text' => 'one '],
                        ['text' => 'two'],
                    ]],
                ]],
                'usageMetadata' => [],
            ]),
        ]);

        $result = (new GeminiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('one two', $result['content']);
    }

    public function test_chat_extracts_function_call(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [
                        ['text' => 'Calling tool.'],
                        ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Tokyo']]],
                    ]],
                ]],
                'usageMetadata' => [],
            ]),
        ]);

        $result = (new GeminiProvider('k'))->chat(
            [['role' => 'user', 'content' => 'weather?']],
            [['name' => 'get_weather']],
        );

        $this->assertSame('Calling tool.', $result['content']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame('get_weather', $result['tool_calls'][0]['name']);
        $this->assertSame(['city' => 'Tokyo'], $result['tool_calls'][0]['arguments']);
        $this->assertStringStartsWith('gemini_', $result['tool_calls'][0]['id']);
    }

    public function test_chat_function_call_default_args_empty(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['functionCall' => ['name' => 'noop']]]],
                ]],
                'usageMetadata' => [],
            ]),
        ]);

        $result = (new GeminiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame([], $result['tool_calls'][0]['arguments']);
    }

    public function test_chat_handles_missing_candidates_gracefully(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => []]),
        ]);

        $result = (new GeminiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertNull($result['content']);
        $this->assertSame([], $result['tool_calls']);
        $this->assertSame(['input_tokens' => 0, 'output_tokens' => 0], $result['usage']);
    }

    // --- Request shaping ---

    public function test_chat_extracts_system_into_system_instruction(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [], 'usageMetadata' => []]),
        ]);

        (new GeminiProvider('k'))->chat([
            ['role' => 'system', 'content' => 'be terse'],
            ['role' => 'user', 'content' => 'hi'],
        ]);

        Http::assertSent(function ($req) {
            $body = $req->data();

            return $body['systemInstruction']['parts'][0]['text'] === 'be terse'
                && count($body['contents']) === 1
                && $body['contents'][0]['role'] === 'user'
                && $body['contents'][0]['parts'][0]['text'] === 'hi';
        });
    }

    public function test_chat_maps_assistant_role_to_model(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [], 'usageMetadata' => []]),
        ]);

        (new GeminiProvider('k'))->chat([
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello'],
            ['role' => 'user', 'content' => 'how?'],
        ]);

        Http::assertSent(function ($req) {
            $contents = $req->data()['contents'];

            return count($contents) === 3
                && $contents[0]['role'] === 'user'
                && $contents[1]['role'] === 'model'
                && $contents[2]['role'] === 'user';
        });
    }

    public function test_chat_includes_api_key_in_query_string(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [], 'usageMetadata' => []]),
        ]);

        (new GeminiProvider('my-gemini-key'))->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'key=my-gemini-key'));
    }

    public function test_chat_uses_constructor_model_in_url(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [], 'usageMetadata' => []]),
        ]);

        (new GeminiProvider('k', 'gemini-1.5-pro'))->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'models/gemini-1.5-pro:generateContent'));
    }

    public function test_chat_options_override_model(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [], 'usageMetadata' => []]),
        ]);

        (new GeminiProvider('k', 'default'))->chat(
            [['role' => 'user', 'content' => 'hi']],
            [],
            ['model' => 'override-model'],
        );

        Http::assertSent(fn ($req) => str_contains($req->url(), 'models/override-model:'));
    }

    public function test_chat_includes_max_tokens_in_generation_config(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [], 'usageMetadata' => []]),
        ]);

        (new GeminiProvider('k'))->chat(
            [['role' => 'user', 'content' => 'hi']],
            [],
            ['max_tokens' => 250],
        );

        Http::assertSent(fn ($req) => $req->data()['generationConfig']['maxOutputTokens'] === 250);
    }

    public function test_chat_omits_generation_config_when_max_tokens_unset(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [], 'usageMetadata' => []]),
        ]);

        (new GeminiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => ! array_key_exists('generationConfig', $req->data()));
    }

    public function test_chat_shapes_tools_as_function_declarations(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [], 'usageMetadata' => []]),
        ]);

        (new GeminiProvider('k'))->chat(
            [['role' => 'user', 'content' => 'hi']],
            [['name' => 'do_thing', 'description' => 'd', 'parameters' => ['type' => 'OBJECT']]],
        );

        Http::assertSent(function ($req) {
            $decl = $req->data()['tools'][0]['functionDeclarations'][0];

            return $decl['name'] === 'do_thing'
                && $decl['description'] === 'd'
                && $decl['parameters']['type'] === 'OBJECT';
        });
    }

    public function test_chat_omits_tools_when_none_passed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [], 'usageMetadata' => []]),
        ]);

        (new GeminiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => ! array_key_exists('tools', $req->data()));
    }

    public function test_chat_uses_constructor_base_url(): void
    {
        Http::fake([
            'gemini-proxy.example.test/*' => Http::response(['candidates' => [], 'usageMetadata' => []]),
        ]);

        (new GeminiProvider('k', 'm', 'https://gemini-proxy.example.test'))
            ->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://gemini-proxy.example.test/v1beta/models/m:generateContent'));
    }

    // --- Error handling ---

    public function test_chat_throws_on_4xx(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'bad'], 400),
        ]);

        $this->expectException(RequestException::class);

        (new GeminiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function test_chat_throws_on_5xx(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'down'], 500),
        ]);

        $this->expectException(RequestException::class);

        (new GeminiProvider('k'))->chat([['role' => 'user', 'content' => 'hi']]);
    }
}
