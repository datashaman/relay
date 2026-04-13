<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Enums\SourceType;
use App\Enums\StuckState;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Services\OrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueViewTest extends TestCase
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

    private function createPipelineIssue(array $overrides = []): Issue
    {
        $source = $this->createSource();

        return Issue::factory()->create(array_merge([
            'source_id' => $source->id,
            'status' => IssueStatus::InProgress,
        ], $overrides));
    }

    public function test_index_redirects_to_first_issue(): void
    {
        $this->markTestSkipped('/issues index route removed — pipeline entry is now Overview.');
    }

    public function test_index_shows_empty_state_when_no_pipeline_issues(): void
    {
        $this->markTestSkipped('/issues index route removed — pipeline entry is now Overview.');
    }

    public function test_index_excludes_queued_and_rejected_issues(): void
    {
        $this->markTestSkipped('/issues index route removed — pipeline entry is now Overview.');
    }

    public function test_show_displays_three_panels(): void
    {
        $issue = $this->createPipelineIssue(['title' => 'Three panel test']);

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertSee('Pipeline Issues');
        $response->assertSee('Three panel test');
        $response->assertSee('Actions');
    }

    public function test_show_displays_issue_list_in_left_panel(): void
    {
        $source = $this->createSource();
        $issue1 = Issue::factory()->create(['source_id' => $source->id, 'status' => IssueStatus::InProgress, 'title' => 'Issue Alpha']);
        $issue2 = Issue::factory()->create(['source_id' => $source->id, 'status' => IssueStatus::Accepted, 'title' => 'Issue Beta']);

        $response = $this->get(route('issues.show', $issue1));

        $response->assertStatus(200);
        $response->assertSee('Issue Alpha');
        $response->assertSee('Issue Beta');
    }

    public function test_show_displays_preflight_doc(): void
    {
        $issue = $this->createPipelineIssue();
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
            'preflight_doc' => 'Test preflight document content',
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertSee('Test preflight document content');
        $response->assertSee('Preflight Doc');
    }

    public function test_show_displays_approval_buttons_when_awaiting(): void
    {
        $issue = $this->createPipelineIssue();
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::AwaitingApproval,
        ]);

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertSee('Approve (A)');
        $response->assertSee('Reject (R)');
        $response->assertSee('Awaiting Approval');
    }

    public function test_show_displays_guidance_form_when_stuck(): void
    {
        $issue = $this->createPipelineIssue(['status' => IssueStatus::Stuck]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::IterationCap,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Stuck,
        ]);

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertSee('Give guidance for retry');
        $response->assertSee('Submit Guidance');
    }

    public function test_show_displays_running_indicator(): void
    {
        $issue = $this->createPipelineIssue();
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Running,
        ]);

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertSee('Verify running');
    }

    public function test_approve_stage(): void
    {
        $issue = $this->createPipelineIssue();
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::AwaitingApproval,
        ]);

        $this->mock(OrchestratorService::class, function ($mock) use ($stage) {
            $mock->shouldReceive('resume')->once();
        });

        $response = $this->post(route('issues.approve', $stage));

        $response->assertRedirect(route('issues.show', $issue));
        $response->assertSessionHas('success');
    }

    public function test_reject_stage(): void
    {
        $issue = $this->createPipelineIssue();
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::AwaitingApproval,
        ]);

        $this->mock(OrchestratorService::class, function ($mock) {
            $mock->shouldReceive('fail')->once();
        });

        $response = $this->post(route('issues.reject-stage', $stage));

        $response->assertRedirect(route('issues.show', $issue));
        $response->assertSessionHas('success');
    }

    public function test_approve_non_awaiting_stage_fails(): void
    {
        $issue = $this->createPipelineIssue();
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::Running,
        ]);

        $response = $this->post(route('issues.approve', $stage));

        $response->assertRedirect(route('issues.show', $issue));
        $response->assertSessionHas('error');
    }

    public function test_submit_guidance(): void
    {
        $issue = $this->createPipelineIssue(['status' => IssueStatus::Stuck]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::IterationCap,
        ]);

        $this->mock(OrchestratorService::class, function ($mock) {
            $mock->shouldReceive('giveGuidance')->once();
        });

        $response = $this->post(route('issues.guidance', $run), [
            'guidance' => 'Try a different approach.',
        ]);

        $response->assertRedirect(route('issues.show', $issue));
        $response->assertSessionHas('success');
    }

    public function test_guidance_on_non_stuck_run_fails(): void
    {
        $issue = $this->createPipelineIssue();
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);

        $response = $this->post(route('issues.guidance', $run), [
            'guidance' => 'Some guidance.',
        ]);

        $response->assertRedirect(route('issues.show', $issue));
        $response->assertSessionHas('error');
    }

    public function test_keyboard_shortcuts_js_present(): void
    {
        $issue = $this->createPipelineIssue();

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertSee('keydown');
        $response->assertSee('TEXTAREA');
    }

    public function test_responsive_classes_present(): void
    {
        $issue = $this->createPipelineIssue();

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('grid-cols-1', $content);
        $this->assertStringContainsString('lg:grid-cols-12', $content);
    }

    public function test_issues_nav_link_present(): void
    {
        $this->markTestSkipped('/issues index removed — nav links are Overview, Activity, Intake, Config.');
    }

    public function test_completed_issue_shows_completed_badge(): void
    {
        $issue = $this->createPipelineIssue(['status' => IssueStatus::Completed]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Completed,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Release,
            'status' => StageStatus::Completed,
        ]);

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertSee('Completed');
    }
}
