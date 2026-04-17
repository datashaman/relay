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
                'redirect_uri' => 'http://localhost:8000/oauth/github/callback',
                'authorize_url' => 'https://github.com/login/oauth/authorize',
                'token_url' => 'https://github.com/login/oauth/access_token',
                'scopes' => ['repo', 'read:org', 'workflow'],
            ],
            'services.jira' => [
                'client_id' => 'test-jira-id',
                'client_secret' => 'test-jira-secret',
                'redirect_uri' => 'http://localhost:8000/oauth/jira/callback',
                'authorize_url' => 'https://auth.atlassian.com/authorize',
                'token_url' => 'https://auth.atlassian.com/oauth/token',
                'scopes' => ['read:jira-work', 'write:jira-work', 'read:jira-user', 'offline_access'],
            ],
        ]);
    }

    public function test_oauth_redirect_route_redirects_to_provider(): void
    {
        $response = $this->get('/oauth/github/redirect');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize', $location);
        $this->assertStringContainsString('client_id=test-github-id', $location);
        $this->assertStringContainsString('state=', $location);
    }

    public function test_oauth_redirect_sets_state_in_cache(): void
    {
        $response = $this->get('/oauth/github/redirect');

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertNotEmpty($params['state']);
        $this->assertEquals('github', Cache::get("oauth_state:{$params['state']}"));
    }

    public function test_jira_redirect_includes_audience_and_prompt(): void
    {
        $response = $this->get('/oauth/jira/redirect');

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('audience=api.atlassian.com', $location);
        $this->assertStringContainsString('prompt=consent', $location);
    }

    public function test_callback_with_invalid_state_redirects_with_error(): void
    {
        $response = $this->get('/oauth/github/callback?code=test-code&state=invalid-state');

        $response->assertRedirect('/intake');
        $response->assertSessionHas('error');
    }

    public function test_callback_with_denied_consent_redirects_with_error(): void
    {
        $response = $this->get('/oauth/github/callback?error=access_denied');

        $response->assertRedirect('/intake');
        $response->assertSessionHas('error', 'OAuth authorization was denied.');
    }

    public function test_callback_with_state_mismatch_redirects_with_error(): void
    {
        $state = 'test-state-123';
        Cache::put("oauth_state:{$state}", 'jira', now()->addMinutes(10));

        $response = $this->get("/oauth/github/callback?code=test-code&state={$state}");

        $response->assertRedirect('/intake');
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

        $response = $this->get("/oauth/github/callback?code=auth-code&state={$state}");

        $source = \App\Models\Source::where('type', 'github')->first();
        $response->assertRedirect(route('github.select-repos', $source));
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

        $this->get("/oauth/github/callback?code=auth-code&state={$state}");

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
        $this->get('/oauth/invalid/redirect')->assertNotFound();
        $this->get('/oauth/invalid/callback?code=x&state=y')->assertNotFound();
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

        $response = $this->get("/oauth/github/callback?code=auth-code&state={$state}");

        $source = Source::where('type', 'github')->first();
        $response->assertRedirect(route('github.select-repos', $source));
        $response->assertSessionHas('success');

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

        $response = $this->get("/oauth/github/callback?code=auth-code&state={$state}");

        $source = Source::where('type', 'github')->first();
        $response->assertRedirect(route('github.select-repos', $source));
        $response->assertSessionHas('success');

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

        $response = $this->delete('/oauth/github/disconnect');

        $response->assertRedirect('/intake');
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

        $response = $this->delete('/oauth/github/disconnect');

        $response->assertRedirect('/intake');
        $response->assertSessionHas('warning');

        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('oauth_tokens', 0);
    }

    public function test_disconnect_nonexistent_connection_returns_error(): void
    {
        $response = $this->delete('/oauth/github/disconnect');

        $response->assertRedirect('/intake');
        $response->assertSessionHas('error', 'No Github connection found.');
    }

    public function test_disconnect_route_rejects_invalid_provider(): void
    {
        $this->delete('/oauth/invalid/disconnect')->assertNotFound();
    }

    public function test_github_redirect_includes_correct_scopes(): void
    {
        $response = $this->get('/oauth/github/redirect');

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

        $response = $this->get("/oauth/github/callback?code=auth-code&state={$state}");

        $response->assertRedirect('/intake');
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('oauth_tokens', 0);
    }

    public function test_jira_redirect_includes_correct_scopes(): void
    {
        $response = $this->get('/oauth/jira/redirect');

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('scope=read%3Ajira-work+write%3Ajira-work+read%3Ajira-user+offline_access', $location);
    }

    public function test_jira_callback_single_site_auto_selects(): void
    {
        $state = 'test-jira-single';
        Cache::put("oauth_state:{$state}", 'jira', now()->addMinutes(10));

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'jira-access-token',
                'refresh_token' => 'jira-refresh-token',
                'expires_in' => 3600,
                'scope' => 'read:jira-work write:jira-work read:jira-user offline_access',
            ]),
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                [
                    'id' => 'cloud-id-abc',
                    'name' => 'My Jira Site',
                    'url' => 'https://mysite.atlassian.net',
                    'scopes' => ['read:jira-work', 'write:jira-work'],
                ],
            ]),
        ]);

        $response = $this->get("/oauth/jira/callback?code=auth-code&state={$state}");

        $source = Source::where('type', 'jira')->first();
        $this->assertNotNull($source);
        $response->assertRedirect(route('jira.select-projects', $source));
        $response->assertSessionHas('success', 'Jira connected. Pick the projects Relay should sync.');

        $this->assertEquals('My Jira Site', $source->external_account);
        $this->assertEquals('cloud-id-abc', $source->config['cloud_id']);
        $this->assertEquals('https://mysite.atlassian.net', $source->config['site_url']);

        $token = OauthToken::where('provider', 'jira')->first();
        $this->assertNotNull($token);
        $this->assertEquals('jira-access-token', $token->access_token);
        $this->assertEquals('jira-refresh-token', $token->refresh_token);
        $this->assertEquals(['read:jira-work', 'write:jira-work', 'read:jira-user', 'offline_access'], $token->scopes);
    }

    public function test_jira_callback_multiple_sites_redirects_to_selection(): void
    {
        $state = 'test-jira-multi';
        Cache::put("oauth_state:{$state}", 'jira', now()->addMinutes(10));

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'jira-access-token',
                'refresh_token' => 'jira-refresh-token',
                'expires_in' => 3600,
                'scope' => 'read:jira-work write:jira-work read:jira-user offline_access',
            ]),
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                ['id' => 'cloud-1', 'name' => 'Site One', 'url' => 'https://site-one.atlassian.net'],
                ['id' => 'cloud-2', 'name' => 'Site Two', 'url' => 'https://site-two.atlassian.net'],
            ]),
        ]);

        $response = $this->get("/oauth/jira/callback?code=auth-code&state={$state}");

        $response->assertRedirect('/jira/select-site');
        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('oauth_tokens', 0);

        $pending = Cache::get('jira_pending_site_selection');
        $this->assertNotNull($pending);
        $this->assertCount(2, $pending['sites']);
        $this->assertEquals('jira-access-token', $pending['token_data']['access_token']);
    }

    public function test_jira_select_site_creates_source(): void
    {
        Cache::put('jira_pending_site_selection', [
            'token_data' => [
                'access_token' => 'jira-token',
                'refresh_token' => 'jira-refresh',
                'expires_in' => 3600,
                'scope' => 'read:jira-work write:jira-work read:jira-user offline_access',
            ],
            'sites' => [
                ['id' => 'cloud-1', 'name' => 'Site One', 'url' => 'https://site-one.atlassian.net'],
                ['id' => 'cloud-2', 'name' => 'Site Two', 'url' => 'https://site-two.atlassian.net'],
            ],
        ], now()->addMinutes(10));

        \Livewire\Livewire::test('pages::jira-select-site')
            ->call('selectSite', 'cloud-2')
            ->assertRedirect('/sources/1/projects');

        $source = Source::where('type', 'jira')->first();
        $this->assertNotNull($source);
        $this->assertEquals('Site Two', $source->external_account);
        $this->assertEquals('cloud-2', $source->config['cloud_id']);

        $this->assertDatabaseCount('oauth_tokens', 1);
        $this->assertNull(Cache::get('jira_pending_site_selection'));
    }

    public function test_jira_select_site_invalid_cloud_id_returns_error(): void
    {
        Cache::put('jira_pending_site_selection', [
            'token_data' => ['access_token' => 'jira-token', 'refresh_token' => 'jira-refresh', 'expires_in' => 3600],
            'sites' => [
                ['id' => 'cloud-1', 'name' => 'Site One', 'url' => 'https://site-one.atlassian.net'],
            ],
        ], now()->addMinutes(10));

        \Livewire\Livewire::test('pages::jira-select-site')
            ->call('selectSite', 'nonexistent')
            ->assertRedirect('/intake');
    }

    public function test_jira_select_site_expired_pending_returns_error(): void
    {
        \Livewire\Livewire::test('pages::jira-select-site')
            ->call('selectSite', 'cloud-1')
            ->assertRedirect('/intake');
    }

    public function test_jira_select_site_page_lists_pending_sites(): void
    {
        Cache::put('jira_pending_site_selection', [
            'token_data' => ['access_token' => 'jira-token'],
            'sites' => [
                ['id' => 'cloud-1', 'name' => 'Site One', 'url' => 'https://site-one.atlassian.net'],
                ['id' => 'cloud-2', 'name' => 'Site Two', 'url' => 'https://site-two.atlassian.net'],
            ],
        ], now()->addMinutes(10));

        \Livewire\Livewire::test('pages::jira-select-site')
            ->assertSee('Site One')
            ->assertSee('Site Two');
    }

    public function test_jira_select_site_page_shows_no_pending_message(): void
    {
        \Livewire\Livewire::test('pages::jira-select-site')
            ->assertSee('No pending Jira authorization');
    }

    public function test_jira_callback_no_accessible_sites_shows_error(): void
    {
        $state = 'test-jira-nosites';
        Cache::put("oauth_state:{$state}", 'jira', now()->addMinutes(10));

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'jira-token',
                'refresh_token' => 'jira-refresh',
                'expires_in' => 3600,
            ]),
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([]),
        ]);

        $response = $this->get("/oauth/jira/callback?code=auth-code&state={$state}");

        $response->assertRedirect('/intake');
        $response->assertSessionHas('error', 'No accessible Jira sites found for this account.');
    }

    public function test_jira_callback_accessible_resources_failure_shows_error(): void
    {
        $state = 'test-jira-resfail';
        Cache::put("oauth_state:{$state}", 'jira', now()->addMinutes(10));

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'jira-token',
                'refresh_token' => 'jira-refresh',
                'expires_in' => 3600,
            ]),
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response('Unauthorized', 401),
        ]);

        $response = $this->get("/oauth/jira/callback?code=auth-code&state={$state}");

        $response->assertRedirect('/intake');
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Failed to fetch Jira sites', session('error'));
    }

    public function test_jira_refresh_token_exchange(): void
    {
        $source = Source::factory()->create(['type' => 'jira', 'config' => ['cloud_id' => 'cloud-abc']]);
        $token = $source->oauthTokens()->create([
            'provider' => 'jira',
            'access_token' => 'old-jira-token',
            'refresh_token' => 'jira-refresh-token',
            'expires_at' => now()->subHour(),
            'scopes' => ['read:jira-work'],
        ]);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'new-jira-token',
                'refresh_token' => 'new-jira-refresh',
                'expires_in' => 3600,
            ]),
        ]);

        $service = app(OauthService::class);
        $refreshed = $service->refreshIfExpired($token);

        $this->assertEquals('new-jira-token', $refreshed->access_token);
        $this->assertEquals('new-jira-refresh', $refreshed->refresh_token);
    }

    public function test_jira_disconnect_revokes_and_deletes(): void
    {
        $source = Source::factory()->create([
            'type' => 'jira',
            'external_account' => 'My Jira Site',
            'config' => ['cloud_id' => 'cloud-abc'],
        ]);
        $source->oauthTokens()->create([
            'provider' => 'jira',
            'access_token' => 'jira-revoke-me',
            'refresh_token' => 'jira-refresh',
            'scopes' => ['read:jira-work'],
        ]);

        Http::fake([
            'auth.atlassian.com/oauth/revoke' => Http::response(null, 200),
        ]);

        $response = $this->delete('/oauth/jira/disconnect');

        $response->assertRedirect('/intake');
        $response->assertSessionHas('success', 'Jira disconnected successfully.');

        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('oauth_tokens', 0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'auth.atlassian.com/oauth/revoke');
        });
    }

    public function test_jira_disconnect_deletes_locally_on_revocation_failure(): void
    {
        $source = Source::factory()->create([
            'type' => 'jira',
            'external_account' => 'My Jira Site',
            'config' => ['cloud_id' => 'cloud-abc'],
        ]);
        $source->oauthTokens()->create([
            'provider' => 'jira',
            'access_token' => 'jira-revoke-me',
            'refresh_token' => 'jira-refresh',
            'scopes' => ['read:jira-work'],
        ]);

        Http::fake([
            'auth.atlassian.com/oauth/revoke' => Http::response('Server Error', 500),
        ]);

        $response = $this->delete('/oauth/jira/disconnect');

        $response->assertRedirect('/intake');
        $response->assertSessionHas('warning');

        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('oauth_tokens', 0);
    }

    public function test_jira_multiple_sites_per_install(): void
    {
        $state1 = 'test-jira-site1';
        Cache::put("oauth_state:{$state1}", 'jira', now()->addMinutes(10));

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'jira-token-1',
                'refresh_token' => 'jira-refresh-1',
                'expires_in' => 3600,
                'scope' => 'read:jira-work write:jira-work read:jira-user offline_access',
            ]),
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                ['id' => 'cloud-1', 'name' => 'Site Alpha', 'url' => 'https://alpha.atlassian.net'],
            ]),
        ]);

        $this->get("/oauth/jira/callback?code=auth-code&state={$state1}");

        $source1 = Source::where('external_account', 'Site Alpha')->first();
        $this->assertNotNull($source1);
        $this->assertEquals('cloud-1', $source1->config['cloud_id']);

        Source::factory()->create([
            'type' => 'jira',
            'external_account' => 'Site Beta',
            'config' => ['cloud_id' => 'cloud-2', 'site_url' => 'https://beta.atlassian.net'],
        ]);

        $this->assertDatabaseCount('sources', 2);
        $jiraSources = Source::where('type', 'jira')->get();
        $this->assertCount(2, $jiraSources);
        $this->assertNotEquals(
            $jiraSources[0]->config['cloud_id'],
            $jiraSources[1]->config['cloud_id'],
        );
    }

    public function test_jira_credentials_configurable_via_env(): void
    {
        config([
            'services.jira.client_id' => 'custom-jira-id',
            'services.jira.client_secret' => 'custom-jira-secret',
        ]);

        $service = app(OauthService::class);
        $config = $service->providerConfig('jira');

        $this->assertEquals('custom-jira-id', $config['client_id']);
        $this->assertEquals('custom-jira-secret', $config['client_secret']);
    }
}
