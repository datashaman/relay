<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Models\OauthToken;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class JiraSelectProjectsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'api.atlassian.com/ex/jira/test-cloud/rest/api/3';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jira' => [
                'client_id' => 'jira-id',
                'client_secret' => 'jira-secret',
                'redirect_uri' => 'http://localhost/oauth/jira/callback',
                'authorize_url' => 'https://auth.atlassian.com/authorize',
                'token_url' => 'https://auth.atlassian.com/oauth/token',
                'scopes' => ['read:jira-work'],
            ],
        ]);
    }

    private function createJiraSource(array $config = []): Source
    {
        $source = Source::factory()->create([
            'type' => SourceType::Jira,
            'is_active' => true,
            'external_account' => 'My Site',
            'config' => array_merge(['cloud_id' => 'test-cloud'], $config),
        ]);
        OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'jira',
            'access_token' => 'jira-test',
            'expires_at' => now()->addHour(),
        ]);

        return $source;
    }

    private function fakeProjectsResponse(array $projects): void
    {
        Http::fake([
            self::BASE.'/project' => Http::response($projects),
        ]);
    }

    public function test_lists_accessible_projects(): void
    {
        $source = $this->createJiraSource();
        $this->fakeProjectsResponse([
            ['id' => '1', 'key' => 'TEST', 'name' => 'Test Project', 'projectTypeKey' => 'software'],
            ['id' => '2', 'key' => 'DEV', 'name' => 'Dev Project'],
        ]);

        Livewire::test('pages::jira-select-projects', ['source' => $source])
            ->assertSee('TEST')
            ->assertSee('Test Project')
            ->assertSee('DEV');
    }

    public function test_toggle_selects_and_deselects_project(): void
    {
        $source = $this->createJiraSource();
        $this->fakeProjectsResponse([
            ['id' => '1', 'key' => 'TEST', 'name' => 'Test Project'],
        ]);

        $component = Livewire::test('pages::jira-select-projects', ['source' => $source])
            ->call('toggle', 'TEST');

        $this->assertEquals(['TEST'], $component->get('selected'));

        $component->call('toggle', 'TEST');
        $this->assertEquals([], $component->get('selected'));
    }

    public function test_save_persists_projects_and_filter_flags(): void
    {
        $source = $this->createJiraSource();
        $this->fakeProjectsResponse([
            ['id' => '1', 'key' => 'TEST', 'name' => 'Test Project'],
        ]);

        Livewire::test('pages::jira-select-projects', ['source' => $source])
            ->call('toggle', 'TEST')
            ->set('onlyMine', true)
            ->set('onlyActiveSprint', true)
            ->call('save')
            ->assertRedirect(route('intake.index'));

        $source->refresh();
        $this->assertEquals(['TEST'], $source->config['projects']);
        $this->assertTrue($source->config['only_mine']);
        $this->assertTrue($source->config['only_active_sprint']);
        $this->assertEquals('test-cloud', $source->config['cloud_id']);
    }

    public function test_loads_existing_selection_into_form(): void
    {
        $source = $this->createJiraSource([
            'projects' => ['DEV'],
            'only_mine' => true,
            'only_active_sprint' => false,
        ]);
        $this->fakeProjectsResponse([
            ['id' => '1', 'key' => 'DEV', 'name' => 'Dev Project'],
        ]);

        $component = Livewire::test('pages::jira-select-projects', ['source' => $source]);

        $this->assertEquals(['DEV'], $component->get('selected'));
        $this->assertTrue($component->get('onlyMine'));
        $this->assertFalse($component->get('onlyActiveSprint'));
    }

    public function test_filters_projects_by_search(): void
    {
        $source = $this->createJiraSource();
        $this->fakeProjectsResponse([
            ['id' => '1', 'key' => 'API', 'name' => 'API Backend'],
            ['id' => '2', 'key' => 'WEB', 'name' => 'Web Frontend'],
        ]);

        Livewire::test('pages::jira-select-projects', ['source' => $source])
            ->set('search', 'web')
            ->assertSee('WEB')
            ->assertDontSee('API Backend');
    }

    public function test_aborts_for_non_jira_source(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'config' => [],
        ]);

        $this->get(route('jira.select-projects', $source))->assertNotFound();
    }
}
