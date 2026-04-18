<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\FrameworkSource;
use App\Enums\SourceType;
use App\Enums\StageName;
use App\Jobs\SyncSourceIssuesJob;
use App\Models\OauthToken;
use App\Models\Repository;
use App\Models\Source;
use App\Services\AiProviders\AiProviderManager;
use App\Services\FrameworkDetector;
use App\Services\GitHubClient;
use App\Services\OauthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class FrameworkDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function createGitHubSource(array $config = []): Source
    {
        return Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'testuser',
            'is_active' => true,
            'config' => array_merge(['repositories' => ['owner/repo']], $config),
        ]);
    }

    private function createToken(Source $source): OauthToken
    {
        OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => $source->type->value,
            'access_token' => 'test-token',
            'expires_at' => now()->addHour(),
        ]);

        return OauthToken::where('source_id', $source->id)->firstOrFail();
    }

    /**
     * Base64-encoded GitHub contents API response body for a single file.
     */
    private function contentsResponse(string $raw): array
    {
        return [
            'type' => 'file',
            'encoding' => 'base64',
            'content' => base64_encode($raw),
            'path' => 'placeholder',
        ];
    }

    private function bindAiProvider(?string $reply): void
    {
        $fakeProvider = new class($reply) implements AiProvider
        {
            public function __construct(private ?string $reply) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                return [
                    'content' => $this->reply,
                    'tool_calls' => [],
                    'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                    'raw' => [],
                ];
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield ['type' => 'message', 'content' => $this->reply, 'tool_calls' => null, 'usage' => null];
            }
        };

        $fakeManager = new class($fakeProvider) extends AiProviderManager
        {
            public function __construct(private AiProvider $provider) {}

            public function resolve(?string $workspaceId = null, ?StageName $stage = null): AiProvider
            {
                return $this->provider;
            }
        };

        $this->app->instance(AiProviderManager::class, $fakeManager);
    }

    public function test_detects_laravel_from_composer_manifest(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        Http::fake([
            // Repo metadata returns ambiguous language so that payload-only step 1 misses.
            'api.github.com/repos/owner/repo' => Http::response([
                'language' => null,
                'topics' => [],
                'description' => '',
            ]),
            'api.github.com/repos/owner/repo/contents/composer.json' => Http::response(
                $this->contentsResponse(json_encode([
                    'require' => ['laravel/framework' => '^12.0'],
                ]))
            ),
            'api.github.com/repos/owner/repo/contents/*' => Http::response(null, 404),
            'api.github.com/repos/owner/repo/issues*' => Http::response([]),
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $repository = Repository::where('name', 'owner/repo')->firstOrFail();
        $this->assertSame('laravel', $repository->framework);
        $this->assertSame(FrameworkSource::Payload, $repository->framework_source);
    }

    public function test_ai_fallback_invoked_when_payload_and_manifests_ambiguous(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        Http::fake([
            'api.github.com/repos/owner/repo' => Http::response([
                'language' => null,
                'topics' => [],
                'description' => '',
            ]),
            // Every manifest returns 404
            'api.github.com/repos/owner/repo/contents/*' => Http::response(null, 404),
            'api.github.com/repos/owner/repo/issues*' => Http::response([]),
        ]);

        $this->bindAiProvider('nextjs');

        SyncSourceIssuesJob::dispatchSync($source);

        $repository = Repository::where('name', 'owner/repo')->firstOrFail();
        $this->assertSame('nextjs', $repository->framework);
        $this->assertSame(FrameworkSource::Ai, $repository->framework_source);
    }

    public function test_ai_output_outside_allowlist_is_coerced_to_other(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        Http::fake([
            'api.github.com/repos/owner/repo' => Http::response([
                'language' => null,
                'topics' => [],
                'description' => '',
            ]),
            'api.github.com/repos/owner/repo/contents/*' => Http::response(null, 404),
            'api.github.com/repos/owner/repo/issues*' => Http::response([]),
        ]);

        $this->bindAiProvider('something-weird');

        SyncSourceIssuesJob::dispatchSync($source);

        $repository = Repository::where('name', 'owner/repo')->firstOrFail();
        $this->assertSame('other', $repository->framework);
        $this->assertSame(FrameworkSource::Ai, $repository->framework_source);
    }

    public function test_manual_override_is_not_overwritten_by_detection(): void
    {
        Repository::factory()->create([
            'name' => 'owner/repo',
            'framework' => 'django',
            'framework_source' => FrameworkSource::Manual,
        ]);

        $repository = Repository::where('name', 'owner/repo')->firstOrFail();

        // Provider manager must not be reached; bind one that would throw if called.
        $this->bindAiProvider('laravel');

        $detector = app(FrameworkDetector::class);

        // Stub a GitHub client that would return laravel-looking payload if asked.
        $source = $this->createGitHubSource();
        $token = $this->createToken($source);
        $oauth = app(OauthService::class);
        $client = new GitHubClient($token, $oauth);

        // No HTTP fakes → if detect() tried to call the API it would fail. The
        // manual-source early-return guarantees we never reach the network.
        Http::fake([
            '*' => Http::response('should-not-be-called', 500),
        ]);

        $detector->detect($client, $repository, 'owner', 'repo');

        $repository->refresh();
        $this->assertSame('django', $repository->framework);
        $this->assertSame(FrameworkSource::Manual, $repository->framework_source);
        Http::assertNothingSent();
    }

    public function test_manual_edit_via_livewire_persists_and_flags_source_manual(): void
    {
        $source = $this->createGitHubSource();

        Livewire::test('pages::intake')
            ->call('saveFramework', $source->id, 'owner/repo', 'rails')
            ->assertOk();

        $repository = Repository::where('name', 'owner/repo')->firstOrFail();
        $this->assertSame('rails', $repository->framework);
        $this->assertSame(FrameworkSource::Manual, $repository->framework_source);
    }

    public function test_detects_nextjs_from_package_json(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        Http::fake([
            'api.github.com/repos/owner/repo' => Http::response([
                'language' => 'JavaScript',
                'topics' => [],
                'description' => '',
            ]),
            'api.github.com/repos/owner/repo/contents/composer.json' => Http::response(null, 404),
            'api.github.com/repos/owner/repo/contents/package.json' => Http::response(
                $this->contentsResponse(json_encode([
                    'dependencies' => ['next' => '^14.0.0', 'react' => '^18.0.0'],
                ]))
            ),
            'api.github.com/repos/owner/repo/contents/*' => Http::response(null, 404),
            'api.github.com/repos/owner/repo/issues*' => Http::response([]),
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $repository = Repository::where('name', 'owner/repo')->firstOrFail();
        $this->assertSame('nextjs', $repository->framework);
        $this->assertSame(FrameworkSource::Payload, $repository->framework_source);
    }
}
