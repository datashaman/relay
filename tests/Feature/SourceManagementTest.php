<?php

namespace Tests\Feature;

use App\Models\OauthToken;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SourceManagementTest extends TestCase
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

    public function test_sources_index_shows_empty_state(): void
    {
        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('No sources connected yet.');
        $response->assertSee('Connect GitHub');
        $response->assertSee('Connect Jira');
    }

    public function test_sources_index_lists_connected_sources(): void
    {
        $github = Source::factory()->create([
            'type' => 'github',
            'name' => 'GitHub Connection',
            'external_account' => 'octocat',
            'is_active' => true,
            'last_synced_at' => now()->subMinutes(5),
        ]);

        $jira = Source::factory()->create([
            'type' => 'jira',
            'name' => 'Jira: My Site',
            'external_account' => 'My Site',
            'is_active' => true,
            'last_synced_at' => null,
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('octocat');
        $response->assertSee('My Site');
        $response->assertSee('GitHub');
        $response->assertSee('Jira');
    }

    public function test_sources_index_shows_last_synced_time(): void
    {
        Source::factory()->create([
            'type' => 'github',
            'last_synced_at' => now()->subHours(2),
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('2 hours ago');
    }

    public function test_add_source_links_to_oauth_redirect(): void
    {
        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee(route('oauth.redirect', 'github'));
        $response->assertSee(route('oauth.redirect', 'jira'));
    }

    public function test_test_connection_github_success(): void
    {
        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                ['id' => 1, 'name' => 'test-repo'],
            ]),
        ]);

        $source = Source::factory()->create(['type' => 'github']);
        OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'test-token',
        ]);

        $response = $this->postJson("/sources/{$source->id}/test");

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'message' => 'Connection successful.']);
    }

    public function test_test_connection_jira_success(): void
    {
        $source = Source::factory()->create([
            'type' => 'jira',
            'config' => ['cloud_id' => 'abc-123'],
        ]);
        OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'jira',
            'access_token' => 'test-jira-token',
        ]);

        Http::fake([
            'api.atlassian.com/ex/jira/abc-123/rest/api/3/project*' => Http::response([
                ['id' => '10000', 'key' => 'TEST', 'name' => 'Test Project'],
            ]),
        ]);

        $response = $this->postJson("/sources/{$source->id}/test");

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'message' => 'Connection successful.']);
    }

    public function test_test_connection_no_token(): void
    {
        $source = Source::factory()->create(['type' => 'github']);

        $response = $this->postJson("/sources/{$source->id}/test");

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'message' => 'No OAuth token found for this source.']);
    }

    public function test_test_connection_api_failure(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response('Server Error', 500),
        ]);

        $source = Source::factory()->create(['type' => 'github']);
        OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'test-token',
        ]);

        $response = $this->postJson("/sources/{$source->id}/test");

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $this->assertStringContainsString('Connection failed:', $response->json('message'));
    }

    public function test_disconnect_requires_confirmation_gate(): void
    {
        $source = Source::factory()->create(['type' => 'github']);

        // Disconnect has moved to the per-source detail page.
        $response = $this->get(route('intake.sources.show', $source));

        $response->assertStatus(200);
        $response->assertSee('Disconnect this source', false);
    }

    public function test_sources_index_displays_session_messages(): void
    {
        $response = $this->withSession(['success' => 'GitHub connected successfully.'])->get('/intake');
        $response->assertSee('GitHub connected successfully.');

        $response = $this->withSession(['error' => 'OAuth authorization was denied.'])->get('/intake');
        $response->assertSee('OAuth authorization was denied.');

        $response = $this->withSession(['warning' => 'Remote revocation failed.'])->get('/intake');
        $response->assertSee('Remote revocation failed.');
    }

    public function test_jira_site_selection_form_renders(): void
    {
        $response = $this->get('/jira/select-site');

        $response->assertStatus(200);
        $response->assertSee('Select a Jira Site');
    }
}
