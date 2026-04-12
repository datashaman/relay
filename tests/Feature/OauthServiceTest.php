<?php

namespace Tests\Feature;

use App\Models\OauthToken;
use App\Models\Source;
use App\Services\OauthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OauthServiceTest extends TestCase
{
    use RefreshDatabase;

    private OauthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github' => [
                'client_id' => 'gh-id',
                'client_secret' => 'gh-secret',
                'redirect_uri' => 'http://localhost:8000/oauth/callback/github',
                'authorize_url' => 'https://github.com/login/oauth/authorize',
                'token_url' => 'https://github.com/login/oauth/access_token',
                'scopes' => ['repo', 'read:org', 'workflow'],
            ],
            'services.jira' => [
                'client_id' => 'jira-id',
                'client_secret' => 'jira-secret',
                'redirect_uri' => 'http://localhost:8000/oauth/callback/jira',
                'authorize_url' => 'https://auth.atlassian.com/authorize',
                'token_url' => 'https://auth.atlassian.com/oauth/token',
                'scopes' => ['read:jira-work', 'write:jira-work', 'read:jira-user', 'offline_access'],
            ],
        ]);

        $this->service = new OauthService;
    }

    public function test_provider_config_returns_config(): void
    {
        $config = $this->service->providerConfig('github');

        $this->assertEquals('gh-id', $config['client_id']);
        $this->assertEquals('gh-secret', $config['client_secret']);
    }

    public function test_provider_config_throws_for_unconfigured_provider(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->providerConfig('unknown');
    }

    public function test_provider_config_throws_for_missing_client_id(): void
    {
        config(['services.broken' => ['client_id' => '', 'client_secret' => 'x']]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->providerConfig('broken');
    }

    public function test_generate_auth_url_contains_required_params(): void
    {
        $url = $this->service->generateAuthUrl('github');

        $this->assertStringContainsString('client_id=gh-id', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('state=', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('scope=repo', $url);
    }

    public function test_generate_auth_url_stores_state_in_cache(): void
    {
        $url = $this->service->generateAuthUrl('github');

        preg_match('/state=([^&]+)/', $url, $matches);
        $state = $matches[1];

        $this->assertEquals('github', Cache::get("oauth_state:{$state}"));
    }

    public function test_jira_auth_url_includes_audience_and_prompt(): void
    {
        $url = $this->service->generateAuthUrl('jira');

        $this->assertStringContainsString('audience=api.atlassian.com', $url);
        $this->assertStringContainsString('prompt=consent', $url);
    }

    public function test_jira_scopes_separated_by_space(): void
    {
        $url = $this->service->generateAuthUrl('jira');

        $this->assertStringContainsString('scope=read%3Ajira-work+write%3Ajira-work', $url);
    }

    public function test_validate_state_returns_provider(): void
    {
        Cache::put('oauth_state:test-state', 'github', now()->addMinutes(10));

        $provider = $this->service->validateState('test-state');

        $this->assertEquals('github', $provider);
        $this->assertNull(Cache::get('oauth_state:test-state'));
    }

    public function test_validate_state_throws_for_invalid_state(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->validateState('invalid-state');
    }

    public function test_exchange_code_posts_to_token_url(): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_test',
                'token_type' => 'bearer',
                'scope' => 'repo',
            ]),
        ]);

        $result = $this->service->exchangeCode('github', 'auth-code');

        $this->assertEquals('gho_test', $result['access_token']);

        Http::assertSent(function ($request) {
            return $request['code'] === 'auth-code'
                && $request['grant_type'] === 'authorization_code';
        });
    }

    public function test_exchange_code_throws_on_failure(): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response('error', 400),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->exchangeCode('github', 'bad-code');
    }

    public function test_fetch_github_user(): void
    {
        Http::fake([
            'api.github.com/user' => Http::response([
                'login' => 'testuser',
                'id' => 12345,
            ]),
        ]);

        $user = $this->service->fetchGitHubUser('test-token');

        $this->assertEquals('testuser', $user['login']);
    }

    public function test_fetch_jira_accessible_resources(): void
    {
        Http::fake([
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                ['id' => 'cloud-1', 'name' => 'My Site', 'url' => 'https://my.atlassian.net'],
            ]),
        ]);

        $resources = $this->service->fetchJiraAccessibleResources('test-token');

        $this->assertCount(1, $resources);
        $this->assertEquals('cloud-1', $resources[0]['id']);
    }

    public function test_revoke_github_token_uses_basic_auth(): void
    {
        Http::fake([
            'api.github.com/applications/gh-id/grant' => Http::response(null, 204),
        ]);

        $this->service->revokeGitHubToken('test-token');

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_revoke_jira_token(): void
    {
        Http::fake([
            'auth.atlassian.com/oauth/revoke' => Http::response(null, 200),
        ]);

        $this->service->revokeJiraToken('test-token');

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request['token'] === 'test-token';
        });
    }

    public function test_store_token_creates_oauth_token(): void
    {
        $source = Source::factory()->create(['type' => 'github']);

        $token = $this->service->storeToken($source, 'github', [
            'access_token' => 'gho_stored',
            'refresh_token' => 'ghr_stored',
            'expires_in' => 3600,
            'scope' => 'repo,read:org',
        ]);

        $this->assertEquals('github', $token->provider);
        $this->assertEquals('gho_stored', $token->access_token);
        $this->assertEquals('ghr_stored', $token->refresh_token);
        $this->assertNotNull($token->expires_at);
        $this->assertEquals(['repo', 'read:org'], $token->scopes);
    }

    public function test_store_token_updates_existing(): void
    {
        $source = Source::factory()->create(['type' => 'github']);
        $existing = OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'old-token',
        ]);

        $token = $this->service->storeToken($source, 'github', [
            'access_token' => 'new-token',
        ]);

        $this->assertEquals($existing->id, $token->id);
        $this->assertEquals('new-token', $token->access_token);
    }

    public function test_refresh_token_exchanges_refresh_token(): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_refreshed',
                'token_type' => 'bearer',
            ]),
        ]);

        $source = Source::factory()->create(['type' => 'github']);
        $token = OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'old',
            'refresh_token' => 'refresh-me',
            'expires_at' => now()->subHour(),
        ]);

        $refreshed = $this->service->refreshToken($token);

        $this->assertEquals('gho_refreshed', $refreshed->access_token);
    }

    public function test_refresh_token_throws_without_refresh_token(): void
    {
        $source = Source::factory()->create(['type' => 'github']);
        $token = OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'refresh_token' => null,
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->refreshToken($token);
    }

    public function test_refresh_if_expired_refreshes_expired_token(): void
    {
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_auto_refreshed',
                'token_type' => 'bearer',
            ]),
        ]);

        $source = Source::factory()->create(['type' => 'github']);
        $token = OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'expired',
            'refresh_token' => 'refresh-me',
            'expires_at' => now()->subMinute(),
        ]);

        $result = $this->service->refreshIfExpired($token);

        $this->assertEquals('gho_auto_refreshed', $result->access_token);
    }

    public function test_refresh_if_expired_skips_valid_token(): void
    {
        $source = Source::factory()->create(['type' => 'github']);
        $token = OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'still-valid',
            'expires_at' => now()->addHour(),
        ]);

        $result = $this->service->refreshIfExpired($token);

        $this->assertEquals('still-valid', $result->access_token);
    }

    public function test_authenticated_request_refreshes_on_401(): void
    {
        Http::fake([
            'https://api.example.com/data' => Http::sequence()
                ->push(null, 401)
                ->push(['ok' => true], 200),
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_retried',
                'token_type' => 'bearer',
            ]),
        ]);

        $source = Source::factory()->create(['type' => 'github']);
        $token = OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'old',
            'refresh_token' => 'refresh-me',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->service->authenticatedRequest($token, 'get', 'https://api.example.com/data');

        $this->assertEquals(200, $response->status());
        $this->assertTrue($response->json('ok'));
    }
}
