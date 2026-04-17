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
use App\Jobs\ResolveConflictsJob;
use App\Services\OrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_conflict_badge_shown_when_run_has_conflicts(): void
    {
        $issue = $this->createPipelineIssue();
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
            'has_conflicts' => true,
            'conflict_detected_at' => now(),
            'conflict_files' => ['src/Foo.php', 'src/Bar.php'],
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Stuck,
        ]);

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertSee('Merge Conflict');
        $response->assertSee('Resolve with AI');
        $response->assertSee('src/Foo.php');
    }

    public function test_no_conflict_badge_when_run_is_clean(): void
    {
        $issue = $this->createPipelineIssue();
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
            'has_conflicts' => false,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertDontSee('Resolve with AI');
    }

    public function test_resolve_conflicts_dispatches_job(): void
    {
        Queue::fake();

        $issue = $this->createPipelineIssue();
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
            'has_conflicts' => true,
            'conflict_detected_at' => now(),
            'conflict_files' => ['a.php'],
        ]);

        $response = $this->post(route('issues.resolve-conflicts', $run));

        $response->assertRedirect(route('issues.show', $issue));
        $response->assertSessionHas('success');
        Queue::assertPushed(ResolveConflictsJob::class, function ($job) use ($run) {
            return $job->run->id === $run->id;
        });
    }

    public function test_resolve_conflicts_rejects_when_no_conflicts(): void
    {
        Queue::fake();

        $issue = $this->createPipelineIssue();
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
            'has_conflicts' => false,
        ]);

        $response = $this->post(route('issues.resolve-conflicts', $run));

        $response->assertSessionHas('error');
        Queue::assertNotPushed(ResolveConflictsJob::class);
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
