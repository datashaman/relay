<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\OauthToken;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GithubSelectReposTest extends TestCase
{
    use RefreshDatabase;

    private function createGithubSourceWithToken(array $config = []): Source
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'is_active' => true,
            'external_account' => 'testuser',
            'config' => $config,
        ]);
        OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'gho_test',
            'expires_at' => now()->addHour(),
        ]);

        return $source;
    }

    private function fakeReposResponse(array $repos): void
    {
        Http::fake([
            'api.github.com/user/repos*' => Http::response($repos, 200, [
                'Link' => '<https://api.github.com/user/repos?page=1>; rel="last"',
            ]),
        ]);
    }

    public function test_lists_accessible_repositories(): void
    {
        $source = $this->createGithubSourceWithToken();
        $this->fakeReposResponse([
            ['full_name' => 'acme/api', 'private' => false, 'description' => 'API service', 'updated_at' => '2026-04-10T00:00:00Z'],
            ['full_name' => 'acme/web', 'private' => true, 'description' => null, 'updated_at' => '2026-04-11T00:00:00Z'],
        ]);

        Livewire::test('pages::github-select-repos', ['source' => $source])
            ->assertSee('acme/api')
            ->assertSee('acme/web')
            ->assertSee('Private');
    }

    public function test_toggle_selects_and_deselects_repo(): void
    {
        $source = $this->createGithubSourceWithToken();
        $this->fakeReposResponse([
            ['full_name' => 'acme/api', 'private' => false, 'description' => null, 'updated_at' => '2026-04-10T00:00:00Z'],
        ]);

        $component = Livewire::test('pages::github-select-repos', ['source' => $source])
            ->call('toggle', 'acme/api');

        $this->assertEquals(['acme/api'], $component->get('selected'));

        $component->call('toggle', 'acme/api');
        $this->assertEquals([], $component->get('selected'));
    }

    public function test_save_persists_selection_to_source_config(): void
    {
        $source = $this->createGithubSourceWithToken();
        $this->fakeReposResponse([
            ['full_name' => 'acme/api', 'private' => false, 'description' => null, 'updated_at' => '2026-04-10T00:00:00Z'],
        ]);

        Livewire::test('pages::github-select-repos', ['source' => $source])
            ->call('toggle', 'acme/api')
            ->call('save')
            ->assertRedirect(route('intake.index'));

        $source->refresh();
        $this->assertEquals(['acme/api'], $source->config['repositories']);
    }

    public function test_save_preserves_other_config_keys(): void
    {
        $source = $this->createGithubSourceWithToken(['existing' => 'value']);
        $this->fakeReposResponse([
            ['full_name' => 'acme/api', 'private' => false, 'description' => null, 'updated_at' => '2026-04-10T00:00:00Z'],
        ]);

        Livewire::test('pages::github-select-repos', ['source' => $source])
            ->call('toggle', 'acme/api')
            ->call('save');

        $source->refresh();
        $this->assertEquals('value', $source->config['existing']);
        $this->assertEquals(['acme/api'], $source->config['repositories']);
    }

    public function test_preselects_existing_repositories(): void
    {
        $source = $this->createGithubSourceWithToken(['repositories' => ['acme/existing']]);
        $this->fakeReposResponse([
            ['full_name' => 'acme/existing', 'private' => false, 'description' => null, 'updated_at' => '2026-04-10T00:00:00Z'],
            ['full_name' => 'acme/new', 'private' => false, 'description' => null, 'updated_at' => '2026-04-11T00:00:00Z'],
        ]);

        $component = Livewire::test('pages::github-select-repos', ['source' => $source]);

        $this->assertEquals(['acme/existing'], $component->get('selected'));
    }

    public function test_shows_error_when_no_token(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'is_active' => true,
            'external_account' => 'orphan',
        ]);

        Livewire::test('pages::github-select-repos', ['source' => $source])
            ->assertSee('No GitHub token found');
    }

    public function test_search_hits_search_api(): void
    {
        $source = $this->createGithubSourceWithToken();

        Http::fake([
            'api.github.com/search/repositories*' => Http::response([
                'total_count' => 1,
                'items' => [
                    ['full_name' => 'testuser/matching-repo', 'private' => false, 'description' => 'A match', 'updated_at' => '2026-04-10T00:00:00Z'],
                ],
            ], 200, ['Link' => '<https://api.github.com/search/repositories?page=1>; rel="last"']),
            'api.github.com/user/repos*' => Http::response([], 200),
        ]);

        Livewire::test('pages::github-select-repos', ['source' => $source])
            ->set('search', 'matching')
            ->assertSee('testuser/matching-repo');

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/search/repositories')
            && str_contains((string) $request->url(), 'user%3Atestuser'));
    }

    public function test_initial_load_uses_user_repos_endpoint(): void
    {
        $source = $this->createGithubSourceWithToken();
        $this->fakeReposResponse([
            ['full_name' => 'acme/api', 'private' => false, 'description' => null, 'updated_at' => '2026-04-10T00:00:00Z'],
        ]);

        Livewire::test('pages::github-select-repos', ['source' => $source]);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/user/repos'));
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/search/repositories'));
    }

    public function test_non_github_source_is_rejected(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::Jira,
            'is_active' => true,
            'external_account' => 'site',
        ]);
        OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'jira',
            'access_token' => 'jira-token',
        ]);

        $response = $this->get(route('github.select-repos', $source));
        $response->assertNotFound();
    }
}
