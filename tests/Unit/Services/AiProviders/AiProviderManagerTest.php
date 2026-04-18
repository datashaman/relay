<?php

namespace Tests\Unit\Services\AiProviders;

use App\Services\AiProviders\AiProviderManager;
use App\Services\AiProviders\AnthropicProvider;
use App\Services\AiProviders\ClaudeCodeCliProvider;
use App\Services\AiProviders\GeminiProvider;
use App\Services\AiProviders\OpenAiProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pure-unit coverage for AiProviderManager::make().
 *
 * resolve() reads ProviderConfig from the database — that path is covered by
 * tests/Feature/AiProviderTest.php under RefreshDatabase. Here we bind only a
 * config repository to a throwaway container so make() can call config() and
 * we can lock down the match arm wiring (defaults, settings overrides, the
 * unknown-provider exception, settings-merge precedence).
 */
class AiProviderManagerTest extends TestCase
{
    private Container $container;

    private AiProviderManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container;
        $this->container->instance('config', new Repository);
        Container::setInstance($this->container);

        $this->manager = new AiProviderManager;
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function setConfig(array $values): void
    {
        $this->container->make('config')->set($values);
    }

    // --- make(): each provider arm ---

    #[DataProvider('providerClassProvider')]
    public function test_make_constructs_provider_class(string $key, string $class): void
    {
        $this->setConfig(["ai.providers.{$key}.api_key" => 'k']);
        $this->assertInstanceOf($class, $this->manager->make($key));
    }

    public static function providerClassProvider(): iterable
    {
        yield 'anthropic' => ['anthropic', AnthropicProvider::class];
        yield 'openai' => ['openai', OpenAiProvider::class];
        yield 'gemini' => ['gemini', GeminiProvider::class];
        yield 'claude_code_cli' => ['claude_code_cli', ClaudeCodeCliProvider::class];
    }

    public function test_make_throws_on_unknown_provider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown AI provider: bogus');

        $this->manager->make('bogus');
    }

    // --- make(): settings merge precedence ---

    public function test_make_uses_default_model_when_not_overridden(): void
    {
        $provider = $this->manager->make('anthropic');
        $this->assertSame('claude-sonnet-4-6', $this->readPrivate($provider, 'model'));
    }

    public function test_make_uses_default_openai_model(): void
    {
        $provider = $this->manager->make('openai');
        $this->assertSame('gpt-4o', $this->readPrivate($provider, 'model'));
    }

    public function test_make_uses_default_gemini_model(): void
    {
        $provider = $this->manager->make('gemini');
        $this->assertSame('gemini-2.5-flash', $this->readPrivate($provider, 'model'));
    }

    public function test_make_uses_config_api_key_and_base_url(): void
    {
        $this->setConfig([
            'ai.providers.anthropic.api_key' => 'cfg-key',
            'ai.providers.anthropic.base_url' => 'https://proxy.example.test',
        ]);

        $provider = $this->manager->make('anthropic');

        $this->assertSame('cfg-key', $this->readPrivate($provider, 'apiKey'));
        $this->assertSame('https://proxy.example.test', $this->readPrivate($provider, 'baseUrl'));
    }

    public function test_make_settings_argument_overrides_config(): void
    {
        $this->setConfig([
            'ai.providers.anthropic.api_key' => 'cfg-key',
            'ai.providers.anthropic.model' => 'claude-from-config',
        ]);

        $provider = $this->manager->make('anthropic', [
            'api_key' => 'override-key',
            'model' => 'claude-override',
        ]);

        $this->assertSame('override-key', $this->readPrivate($provider, 'apiKey'));
        $this->assertSame('claude-override', $this->readPrivate($provider, 'model'));
    }

    public function test_make_claude_code_cli_uses_defaults(): void
    {
        /** @var ClaudeCodeCliProvider $provider */
        $provider = $this->manager->make('claude_code_cli');

        $this->assertStringStartsWith('claude --dangerously-skip-permissions', $this->readPrivate($provider, 'command'));
        $this->assertNull($this->readPrivate($provider, 'workingDirectory'));
        $this->assertSame(300, $this->readPrivate($provider, 'timeout'));
    }

    public function test_make_claude_code_cli_honours_overrides(): void
    {
        /** @var ClaudeCodeCliProvider $provider */
        $provider = $this->manager->make('claude_code_cli', [
            'command' => '/usr/local/bin/claude --print',
            'working_directory' => '/tmp/work',
            'timeout' => 60,
        ]);

        $this->assertSame('/usr/local/bin/claude --print', $this->readPrivate($provider, 'command'));
        $this->assertSame('/tmp/work', $this->readPrivate($provider, 'workingDirectory'));
        $this->assertSame(60, $this->readPrivate($provider, 'timeout'));
    }

    public function test_make_falls_back_to_empty_string_api_key_when_unconfigured(): void
    {
        $provider = $this->manager->make('openai');
        $this->assertSame('', $this->readPrivate($provider, 'apiKey'));
    }

    private function readPrivate(object $instance, string $name): mixed
    {
        $prop = new ReflectionProperty($instance, $name);

        return $prop->getValue($instance);
    }
}
