<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\SourceType;
use App\Jobs\SyncSourceIssuesJob;
use App\Models\Issue;
use App\Models\Source;
use App\Services\IssueIntakeService;
use App\Services\OauthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IssueQueueTest extends TestCase
{
    use RefreshDatabase;

    private function createSource(array $overrides = []): Source
    {
        return Source::factory()->create(array_merge([
            'type' => SourceType::GitHub,
            'is_active' => true,
            'external_account' => 'testuser',
        ], $overrides));
    }

    private function createIssue(Source $source, array $overrides = []): Issue
    {
        return Issue::factory()->create(array_merge([
            'source_id' => $source->id,
            'status' => IssueStatus::Queued,
        ], $overrides));
    }

    public function test_queue_view_shows_queued_issues(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source, ['title' => 'Fix the login bug']);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('Fix the login bug');
        $response->assertSee('Intake Control');
    }

    public function test_queue_view_does_not_show_rejected_issues(): void
    {
        $source = $this->createSource();
        $this->createIssue($source, [
            'title' => 'Rejected issue',
            'status' => IssueStatus::Rejected,
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertDontSee('Rejected issue');
    }

    public function test_queue_view_groups_by_source(): void
    {
        $source1 = $this->createSource(['external_account' => 'org1']);
        $source2 = $this->createSource(['external_account' => 'org2']);
        $this->createIssue($source1, ['title' => 'Issue from org1']);
        $this->createIssue($source2, ['title' => 'Issue from org2']);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('org1');
        $response->assertSee('org2');
        $response->assertSee('Issue from org1');
        $response->assertSee('Issue from org2');
    }

    public function test_accept_queued_issue(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source, ['title' => 'Accept me']);

        $response = $this->post("/issues/{$issue->id}/accept");

        $response->assertRedirect('/intake');
        $response->assertSessionHas('success');
        $this->assertEquals(IssueStatus::InProgress, $issue->fresh()->status);
        $this->assertNotNull($issue->fresh()->runs()->first());
    }

    public function test_accept_non_queued_issue_fails(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source, ['status' => IssueStatus::Accepted]);

        $response = $this->post("/issues/{$issue->id}/accept");

        $response->assertRedirect('/intake');
        $response->assertSessionHas('error');
    }

    public function test_reject_queued_issue(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source, ['title' => 'Reject me']);

        $response = $this->post("/issues/{$issue->id}/reject");

        $response->assertRedirect('/intake');
        $response->assertSessionHas('success');
        $this->assertEquals(IssueStatus::Rejected, $issue->fresh()->status);
    }

    public function test_reject_non_queued_issue_fails(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source, ['status' => IssueStatus::Accepted]);

        $response = $this->post("/issues/{$issue->id}/reject");

        $response->assertRedirect('/intake');
        $response->assertSessionHas('error');
    }

    public function test_rejected_issues_hidden_from_queue(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source, ['title' => 'Unique title for hiding test']);
        $issue->update(['status' => IssueStatus::Rejected]);

        $response = $this->get('/intake');

        $response->assertDontSee('Unique title for hiding test');
    }

    public function test_toggle_pause_intake(): void
    {
        $source = $this->createSource(['is_intake_paused' => false]);

        $response = $this->post("/sources/{$source->id}/toggle-pause");

        $response->assertRedirect('/intake');
        $response->assertSessionHas('success');
        $this->assertTrue($source->fresh()->is_intake_paused);
    }

    public function test_toggle_resume_intake(): void
    {
        $source = $this->createSource(['is_intake_paused' => true]);

        $response = $this->post("/sources/{$source->id}/toggle-pause");

        $response->assertRedirect('/intake');
        $this->assertFalse($source->fresh()->is_intake_paused);
    }

    public function test_pause_with_backlog_threshold(): void
    {
        $source = $this->createSource(['is_intake_paused' => false]);

        $response = $this->post("/sources/{$source->id}/toggle-pause", [
            'backlog_threshold' => 10,
        ]);

        $response->assertRedirect('/intake');
        $this->assertTrue($source->fresh()->is_intake_paused);
        $this->assertEquals(10, $source->fresh()->backlog_threshold);
    }

    public function test_sync_skipped_when_intake_paused(): void
    {
        $source = $this->createSource(['is_intake_paused' => true]);
        $source->oauthTokens()->create([
            'provider' => 'github',
            'access_token' => 'test-token',
            'token_type' => 'bearer',
        ]);

        $job = new SyncSourceIssuesJob($source);
        $job->handle(app(OauthService::class), app(IssueIntakeService::class));

        $this->assertEquals(0, Issue::count());
    }

    public function test_sync_skipped_when_backlog_threshold_reached(): void
    {
        $source = $this->createSource([
            'is_intake_paused' => false,
            'backlog_threshold' => 2,
        ]);
        $this->createIssue($source);
        $this->createIssue($source, ['external_id' => 'ext-2']);

        $source->oauthTokens()->create([
            'provider' => 'github',
            'access_token' => 'test-token',
            'token_type' => 'bearer',
        ]);

        $job = new SyncSourceIssuesJob($source);
        $job->handle(app(OauthService::class), app(IssueIntakeService::class));

        $this->assertEquals(2, Issue::count());
    }

    public function test_empty_queue_shows_message(): void
    {
        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('No incoming issues');
    }

    public function test_queue_nav_link_present(): void
    {
        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('Intake');
    }

    public function test_pause_status_shown_in_queue(): void
    {
        $source = $this->createSource(['is_intake_paused' => true]);

        // Intake index shows the paused badge on the summary row.
        $response = $this->get('/intake');
        $response->assertStatus(200);
        $response->assertSee('Paused');

        // The Resume action lives on the per-source detail page.
        $detailResponse = $this->get(route('intake.sources.show', $source));
        $detailResponse->assertStatus(200);
        $detailResponse->assertSee('Resume');
    }

    public function test_queued_issues_show_accept_reject_buttons(): void
    {
        $source = $this->createSource();
        $this->createIssue($source, ['title' => 'Pending issue']);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('Accept');
        $response->assertSee('Reject');
    }

    public function test_accepted_issues_no_action_buttons(): void
    {
        $source = $this->createSource();
        $this->createIssue($source, [
            'title' => 'Accepted issue',
            'status' => IssueStatus::Accepted,
        ]);

        $response = $this->get('/intake');

        $content = $response->getContent();
        $this->assertStringNotContainsString('issues/'.Issue::first()->id.'/accept', $content);
    }

    public function test_toggle_pause_repo_adds_and_removes_entry(): void
    {
        $source = $this->createSource([
            'type' => SourceType::GitHub,
            'config' => ['repositories' => ['owner/repo1', 'owner/repo2']],
        ]);

        // togglePauseRepo moved to the source-detail page component.
        Livewire::test('pages::source-detail', ['source' => $source])
            ->call('togglePauseRepo', 'owner/repo1');

        $this->assertEquals(['owner/repo1'], $source->fresh()->paused_repositories);

        Livewire::test('pages::source-detail', ['source' => $source])
            ->call('togglePauseRepo', 'owner/repo1');

        $this->assertEquals([], $source->fresh()->paused_repositories);
    }

    public function test_toggle_pause_repo_ignores_repo_not_in_config(): void
    {
        $source = $this->createSource([
            'type' => SourceType::GitHub,
            'config' => ['repositories' => ['owner/repo1']],
        ]);

        // togglePauseRepo moved to the source-detail page component.
        Livewire::test('pages::source-detail', ['source' => $source])
            ->call('togglePauseRepo', 'owner/unknown');

        $this->assertNull($source->fresh()->paused_repositories);
    }

    public function test_toggle_pause_repo_does_not_affect_source_pause(): void
    {
        $source = $this->createSource([
            'type' => SourceType::GitHub,
            'is_intake_paused' => false,
            'config' => ['repositories' => ['owner/repo1', 'owner/repo2']],
        ]);

        // togglePauseRepo moved to the source-detail page component.
        Livewire::test('pages::source-detail', ['source' => $source])
            ->call('togglePauseRepo', 'owner/repo1');

        $fresh = $source->fresh();
        $this->assertFalse($fresh->is_intake_paused);
        $this->assertEquals(['owner/repo1'], $fresh->paused_repositories);
    }

    public function test_labels_displayed_on_issues(): void
    {
        $source = $this->createSource();
        $this->createIssue($source, [
            'title' => 'Labeled issue',
            'labels' => ['bug', 'urgent'],
        ]);

        $response = $this->get('/intake');

        $response->assertStatus(200);
        $response->assertSee('bug');
        $response->assertSee('urgent');
    }
}
