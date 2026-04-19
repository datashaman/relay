<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\SourceType;
use App\Jobs\SyncSourceIssuesJob;
use App\Models\Issue;
use App\Models\OauthToken;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SourceDetailPageTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Intake summary row tests
    // -------------------------------------------------------------------------

    public function test_intake_summary_row_shows_github_source_with_health_pill(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'octocat',
            'is_active' => true,
            'sync_error' => null,
            'config' => ['repositories' => ['octocat/hello', 'octocat/world']],
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('octocat');
        $response->assertSee('Healthy');
        $response->assertSee('Manage');
        $response->assertSee(route('intake.sources.show', $source), false);
    }

    public function test_intake_summary_row_shows_correct_repo_count(): void
    {
        Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'octocat',
            'is_active' => true,
            'config' => ['repositories' => ['octocat/a', 'octocat/b', 'octocat/c']],
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('3 repos');
    }

    public function test_intake_summary_row_shows_jira_project_count(): void
    {
        Source::factory()->create([
            'type' => SourceType::Jira,
            'external_account' => 'My Site',
            'is_active' => true,
            'config' => ['projects' => ['PROJ', 'BUG', 'FEAT']],
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('3 projects');
    }

    public function test_intake_summary_row_queued_count_reflects_active_queued_issues(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'octocat',
            'is_active' => true,
            'config' => ['repositories' => ['octocat/hello']],
        ]);

        Issue::factory()->count(2)->create([
            'source_id' => $source->id,
            'status' => IssueStatus::Queued,
            'archived_at' => null,
        ]);

        // Archived issue — should not count
        Issue::factory()->create([
            'source_id' => $source->id,
            'status' => IssueStatus::Queued,
            'archived_at' => now(),
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('2 queued');
    }

    public function test_intake_summary_row_shows_healthy_pill_when_active_no_errors(): void
    {
        Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'octocat',
            'is_active' => true,
            'sync_error' => null,
            'config' => ['repositories' => ['octocat/hello']],
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('Healthy');
    }

    public function test_intake_summary_row_shows_degraded_pill_when_sync_error(): void
    {
        Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'octocat',
            'is_active' => true,
            'sync_error' => 'Rate limit exceeded',
            'config' => ['repositories' => ['octocat/hello']],
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('Degraded');
    }

    public function test_intake_summary_row_shows_disconnected_pill_when_not_active(): void
    {
        Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'octocat',
            'is_active' => false,
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('Disconnected');
    }

    public function test_intake_summary_row_shows_last_synced_time(): void
    {
        Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'octocat',
            'is_active' => true,
            'last_synced_at' => now()->subHours(2),
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('synced');
        $response->assertSee('2 hours ago');
    }

    // -------------------------------------------------------------------------
    // Source detail page render tests
    // -------------------------------------------------------------------------

    public function test_source_detail_page_renders_for_github_source(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'octocat',
            'is_active' => true,
            'config' => ['repositories' => ['octocat/hello', 'octocat/world']],
        ]);

        $response = $this->get(route('intake.sources.show', $source));

        $response->assertStatus(200);
        $response->assertSee('octocat');
        $response->assertSee('Connection');
        $response->assertSee('Scope');
        $response->assertSee('Webhook');
        $response->assertSee('Intake Rules');
        $response->assertSee('octocat/hello');
        $response->assertSee('octocat/world');
        $response->assertSee('Disconnect this source');
    }

    public function test_source_detail_page_renders_for_jira_source(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::Jira,
            'external_account' => 'My Jira Site',
            'is_active' => true,
            'config' => ['projects' => ['PROJ', 'BUG'], 'cloud_id' => 'abc-123'],
        ]);

        $response = $this->get(route('intake.sources.show', $source));

        $response->assertStatus(200);
        $response->assertSee('My Jira Site');
        $response->assertSee('Connection');
        $response->assertSee('Scope');
        $response->assertSee('Webhook');
        $response->assertSee('Intake Rules');
        $response->assertSee('Components');
        $response->assertSee('PROJ');
        $response->assertSee('BUG');
        $response->assertSee('Disconnect this source');
    }

    public function test_source_detail_page_shows_danger_zone_with_disconnect(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'is_active' => true,
        ]);

        $response = $this->get(route('intake.sources.show', $source));

        $response->assertStatus(200);
        $response->assertSee('Danger Zone');
        $response->assertSee('Disconnect this source');
    }

    public function test_source_detail_page_shows_manage_link_back_to_intake(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'is_active' => true,
        ]);

        $response = $this->get(route('intake.sources.show', $source));

        $response->assertStatus(200);
        $response->assertSee(route('intake.index'), false);
    }

    public function test_source_detail_page_shows_github_repos_section_link(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'is_active' => true,
            'config' => ['repositories' => []],
        ]);

        $response = $this->get(route('intake.sources.show', $source));

        $response->assertStatus(200);
        $response->assertSee(route('github.select-repos', $source), false);
    }

    public function test_source_detail_page_shows_jira_projects_section_link(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::Jira,
            'is_active' => true,
            'config' => [],
        ]);

        $response = $this->get(route('intake.sources.show', $source));

        $response->assertStatus(200);
        $response->assertSee(route('jira.select-projects', $source), false);
        $response->assertSee(route('components.index', $source), false);
    }

    // -------------------------------------------------------------------------
    // Sub-action tests on the detail page
    // -------------------------------------------------------------------------

    public function test_toggle_pause_repo_pauses_an_active_repo(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'is_active' => true,
            'config' => ['repositories' => ['octocat/hello', 'octocat/world']],
            'paused_repositories' => [],
        ]);

        Livewire::test('pages::source-detail', ['source' => $source])
            ->call('togglePauseRepo', 'octocat/hello')
            ->assertHasNoErrors();

        $source->refresh();
        $this->assertContains('octocat/hello', $source->paused_repositories);
        $this->assertNotContains('octocat/world', $source->paused_repositories);
    }

    public function test_toggle_pause_repo_resumes_a_paused_repo(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'is_active' => true,
            'config' => ['repositories' => ['octocat/hello']],
            'paused_repositories' => ['octocat/hello'],
        ]);

        Livewire::test('pages::source-detail', ['source' => $source])
            ->call('togglePauseRepo', 'octocat/hello')
            ->assertHasNoErrors();

        $source->refresh();
        $this->assertNotContains('octocat/hello', $source->paused_repositories);
    }

    public function test_toggle_pause_toggles_intake_paused_state(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'is_active' => true,
            'is_intake_paused' => false,
        ]);

        Livewire::test('pages::source-detail', ['source' => $source])
            ->call('togglePause')
            ->assertHasNoErrors();

        $source->refresh();
        $this->assertTrue($source->is_intake_paused);
    }

    public function test_sync_now_dispatches_job(): void
    {
        Queue::fake();

        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'is_active' => true,
        ]);

        Livewire::test('pages::source-detail', ['source' => $source])
            ->call('syncNow')
            ->assertHasNoErrors();

        Queue::assertPushed(SyncSourceIssuesJob::class, function (SyncSourceIssuesJob $job) use ($source) {
            return $job->source->id === $source->id;
        });
    }

    public function test_test_connection_with_no_token_returns_failure(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'is_active' => true,
        ]);

        Livewire::test('pages::source-detail', ['source' => $source])
            ->call('testConnection')
            ->assertSet('testResult.ok', false);
    }

    public function test_test_connection_with_valid_token_returns_success(): void
    {
        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                ['id' => 1, 'name' => 'test-repo', 'full_name' => 'octocat/test-repo'],
            ]),
        ]);

        $source = Source::factory()->create([
            'type' => SourceType::GitHub,
            'is_active' => true,
        ]);

        OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'test-token',
            'expires_at' => now()->addHour(),
        ]);

        Livewire::test('pages::source-detail', ['source' => $source])
            ->call('testConnection')
            ->assertSet('testResult.ok', true);
    }
}
