<?php

namespace Tests\Feature;

use App\Models\OauthToken;
use App\Models\Source;
use App\Services\OauthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OauthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github' => [
                'client_id' => 'test-github-id',
                'client_secret' => 'test-github-secret',
                'redirect_uri' => 'http://localhost:8000/oauth/callback/github',
                'authorize_url' => 'https://github.com/login/oauth/authorize',
                'token_url' => 'https://github.com/login/oauth/access_token',
                'scopes' => ['repo', 'read:org', 'workflow'],
            ],
            'services.jira' => [
                'client_id' => 'test-jira-id',
                'client_secret' => 'test-jira-secret',
                'redirect_uri' => 'http://localhost:8000/oauth/callback/jira',
                'authorize_url' => 'https://auth.atlassian.com/authorize',
                'token_url' => 'https://auth.atlassian.com/oauth/token',
                'scopes' => ['read:jira-work', 'write:jira-work', 'read:jira-user', 'offline_access'],
            ],
        ]);
    }

    public function test_oauth_redirect_route_redirects_to_provider(): void
    {
        $response = $this->get('/oauth/redirect/github');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize', $location);
        $this->assertStringContainsString('client_id=test-github-id', $location);
        $this->assertStringContainsString('state=', $location);
    }

    public function test_oauth_redirect_sets_state_in_cache(): void
    {
        $response = $this->get('/oauth/redirect/github');

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertNotEmpty($params['state']);
        $this->assertEquals('github', Cache::get("oauth_state:{$params['state']}"));
    }

    public function test_jira_redirect_includes_audience_and_prompt(): void
    {
        $response = $this->get('/oauth/redirect/jira');

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('audience=api.atlassian.com', $location);
        $this->assertStringContainsString('prompt=consent', $location);
    }

    public function test_callback_with_invalid_state_redirects_with_error(): void
    {
        $response = $this->get('/oauth/callback/github?code=test-code&state=invalid-state');

        $response->assertRedirect('/sources');
        $response->assertSessionHas('error');
    }

    public function test_callback_with_denied_consent_redirects_with_error(): void
    {
        $response = $this->get('/oauth/callback/github?error=access_denied');

        $response->assertRedirect('/sources');
        $response->assertSessionHas('error', 'OAuth authorization was denied.');
    }

    public function test_callback_with_state_mismatch_redirects_with_error(): void
    {
        $state = 'test-state-123';
        Cache::put("oauth_state:{$state}", 'jira', now()->addMinutes(10));

        $response = $this->get("/oauth/callback/github?code=test-code&state={$state}");

        $response->assertRedirect('/sources');
        $response->assertSessionHas('error', 'OAuth state mismatch.');
    }

    public function test_successful_callback_stores_token(): void
    {
        $state = 'test-state-456';
        Cache::put("oauth_state:{$state}", 'github', now()->addMinutes(10));

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_test_access_token',
                'refresh_token' => 'ghr_test_refresh_token',
                'expires_in' => 3600,
                'scope' => 'repo,read:org,workflow',
            ]),
            'api.github.com/user' => Http::response([
                'login' => 'testuser',
                'id' => 12345,
            ]),
        ]);

        $response = $this->get("/oauth/callback/github?code=auth-code&state={$state}");

        $response->assertRedirect('/sources');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('sources', ['type' => 'github', 'external_account' => 'testuser']);
        $this->assertDatabaseCount('oauth_tokens', 1);

        $token = OauthToken::first();
        $this->assertEquals('github', $token->provider);
        $this->assertEquals('gho_test_access_token', $token->access_token);
        $this->assertEquals('ghr_test_refresh_token', $token->refresh_token);
        $this->assertNotNull($token->expires_at);
        $this->assertEquals(['repo', 'read:org', 'workflow'], $token->scopes);
    }

    public function test_state_is_consumed_after_callback(): void
    {
        $state = 'test-state-789';
        Cache::put("oauth_state:{$state}", 'github', now()->addMinutes(10));

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_token',
                'expires_in' => 3600,
            ]),
            'api.github.com/user' => Http::response([
                'login' => 'testuser',
            ]),
        ]);

        $this->get("/oauth/callback/github?code=auth-code&state={$state}");

        $this->assertNull(Cache::get("oauth_state:{$state}"));
    }

    public function test_tokens_encrypted_at_rest(): void
    {
        $source = Source::factory()->create(['type' => 'github']);
        $token = $source->oauthTokens()->create([
            'provider' => 'github',
            'access_token' => 'secret-access-token',
            'refresh_token' => 'secret-refresh-token',
            'scopes' => ['repo'],
        ]);

        $raw = \DB::table('oauth_tokens')->where('id', $token->id)->first();
        $this->assertNotEquals('secret-access-token', $raw->access_token);
        $this->assertNotEquals('secret-refresh-token', $raw->refresh_token);

        $token->refresh();
        $this->assertEquals('secret-access-token', $token->access_token);
        $this->assertEquals('secret-refresh-token', $token->refresh_token);
    }

    public function test_token_refresh_on_expiry(): void
    {
        $source = Source::factory()->create(['type' => 'github']);
        $token = $source->oauthTokens()->create([
            'provider' => 'github',
            'access_token' => 'old-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->subHour(),
            'scopes' => ['repo'],
        ]);

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
            ]),
        ]);

        $service = app(OauthService::class);
        $refreshed = $service->refreshIfExpired($token);

        $this->assertEquals('new-access-token', $refreshed->access_token);
        $this->assertEquals('new-refresh-token', $refreshed->refresh_token);
    }

    public function test_authenticated_request_refreshes_on_401(): void
    {
        $source = Source::factory()->create(['type' => 'github']);
        $token = $source->oauthTokens()->create([
            'provider' => 'github',
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'scopes' => ['repo'],
        ]);

        Http::fake([
            'api.github.com/user' => Http::sequence()
                ->push(null, 401)
                ->push(['login' => 'testuser'], 200),
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'new-token',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ]),
        ]);

        $service = app(OauthService::class);
        $response = $service->authenticatedRequest($token, 'get', 'https://api.github.com/user');

        $this->assertEquals(200, $response->status());
        $this->assertEquals('testuser', $response->json('login'));
    }

    public function test_is_expired_helper(): void
    {
        $expired = OauthToken::factory()->create(['expires_at' => now()->subHour()]);
        $valid = OauthToken::factory()->create(['expires_at' => now()->addHour()]);
        $noExpiry = OauthToken::factory()->create(['expires_at' => null]);

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($valid->isExpired());
        $this->assertFalse($noExpiry->isExpired());
    }

    public function test_invalid_provider_route_returns_404(): void
    {
        $this->get('/oauth/redirect/invalid')->assertNotFound();
        $this->get('/oauth/callback/invalid?code=x&state=y')->assertNotFound();
    }

    public function test_provider_config_throws_for_unconfigured_provider(): void
    {
        config(['services.github' => null]);

        $service = app(OauthService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->providerConfig('github');
    }

    public function test_github_callback_fetches_username_as_account_name(): void
    {
        $state = 'test-state-username';
        Cache::put("oauth_state:{$state}", 'github', now()->addMinutes(10));

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_token',
                'expires_in' => 3600,
                'scope' => 'repo,read:org,workflow',
            ]),
            'api.github.com/user' => Http::response([
                'login' => 'octocat',
                'id' => 1,
                'name' => 'The Octocat',
            ]),
        ]);

        $response = $this->get("/oauth/callback/github?code=auth-code&state={$state}");

        $response->assertRedirect('/sources');
        $response->assertSessionHas('success', 'Github connected successfully.');

        $source = Source::where('type', 'github')->first();
        $this->assertNotNull($source);
        $this->assertEquals('octocat', $source->external_account);
    }

    public function test_github_callback_graceful_degradation_on_user_fetch_failure(): void
    {
        $state = 'test-state-userfail';
        Cache::put("oauth_state:{$state}", 'github', now()->addMinutes(10));

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_token',
                'expires_in' => 3600,
            ]),
            'api.github.com/user' => Http::response('Internal Server Error', 500),
        ]);

        $response = $this->get("/oauth/callback/github?code=auth-code&state={$state}");

        $response->assertRedirect('/sources');
        $response->assertSessionHas('success');

        $source = Source::where('type', 'github')->first();
        $this->assertNotNull($source);
        $this->assertEquals('GitHub (unknown)', $source->external_account);
        $this->assertDatabaseCount('oauth_tokens', 1);
    }

    public function test_github_disconnect_revokes_and_deletes(): void
    {
        $source = Source::factory()->create(['type' => 'github', 'external_account' => 'octocat']);
        $source->oauthTokens()->create([
            'provider' => 'github',
            'access_token' => 'gho_revoke_me',
            'refresh_token' => null,
            'scopes' => ['repo'],
        ]);

        Http::fake([
            'api.github.com/applications/test-github-id/grant' => Http::response(null, 204),
        ]);

        $response = $this->delete('/oauth/disconnect/github');

        $response->assertRedirect('/sources');
        $response->assertSessionHas('success', 'Github disconnected successfully.');

        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('oauth_tokens', 0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.github.com/applications/test-github-id/grant');
        });
    }

    public function test_github_disconnect_deletes_locally_on_revocation_failure(): void
    {
        $source = Source::factory()->create(['type' => 'github', 'external_account' => 'octocat']);
        $source->oauthTokens()->create([
            'provider' => 'github',
            'access_token' => 'gho_revoke_me',
            'refresh_token' => null,
            'scopes' => ['repo'],
        ]);

        Http::fake([
            'api.github.com/applications/test-github-id/grant' => Http::response('Server Error', 500),
        ]);

        $response = $this->delete('/oauth/disconnect/github');

        $response->assertRedirect('/sources');
        $response->assertSessionHas('warning');

        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('oauth_tokens', 0);
    }

    public function test_disconnect_nonexistent_connection_returns_error(): void
    {
        $response = $this->delete('/oauth/disconnect/github');

        $response->assertRedirect('/sources');
        $response->assertSessionHas('error', 'No Github connection found.');
    }

    public function test_disconnect_route_rejects_invalid_provider(): void
    {
        $this->delete('/oauth/disconnect/invalid')->assertNotFound();
    }

    public function test_github_redirect_includes_correct_scopes(): void
    {
        $response = $this->get('/oauth/redirect/github');

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('scope=repo%2Cread%3Aorg%2Cworkflow', $location);
    }

    public function test_github_credentials_configurable_via_env(): void
    {
        config([
            'services.github.client_id' => 'custom-app-id',
            'services.github.client_secret' => 'custom-app-secret',
        ]);

        $service = app(OauthService::class);
        $config = $service->providerConfig('github');

        $this->assertEquals('custom-app-id', $config['client_id']);
        $this->assertEquals('custom-app-secret', $config['client_secret']);
    }

    public function test_callback_network_failure_on_token_exchange_shows_error(): void
    {
        $state = 'test-state-netfail';
        Cache::put("oauth_state:{$state}", 'github', now()->addMinutes(10));

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response('Connection refused', 500),
        ]);

        $response = $this->get("/oauth/callback/github?code=auth-code&state={$state}");

        $response->assertRedirect('/sources');
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('oauth_tokens', 0);
    }
}
