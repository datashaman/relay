<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Enums\SourceType;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Models\StageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunProgressTest extends TestCase
{
    use RefreshDatabase;

    private function createRunningRun(): Run
    {
        $source = Source::factory()->create(['type' => SourceType::GitHub, 'is_active' => true]);
        $issue = Issue::factory()->create(['source_id' => $source->id, 'status' => IssueStatus::InProgress]);

        return Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);
    }

    public function test_progress_returns_json_with_stages(): void
    {
        $run = $this->createRunningRun();
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::Completed,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);

        $response = $this->getJson(route('runs.progress', $run));

        $response->assertOk();
        $response->assertJsonPath('run_id', $run->id);
        $response->assertJsonPath('run_status', 'running');
        $response->assertJsonCount(2, 'stages');
        $response->assertJsonPath('live.current_stage', 'implement');
        $response->assertJsonPath('live.current_status', 'running');
    }

    public function test_progress_includes_diff_data_during_implement(): void
    {
        $run = $this->createRunningRun();
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => 'diff_updated',
            'payload' => ['diff' => '+ added line', 'changed_files' => ['app/Test.php']],
        ]);

        $response = $this->getJson(route('runs.progress', $run));

        $response->assertOk();
        $response->assertJsonPath('live.diff', '+ added line');
        $response->assertJsonPath('live.changed_files', ['app/Test.php']);
    }

    public function test_progress_includes_test_output_during_verify(): void
    {
        $run = $this->createRunningRun();
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::Completed,
        ]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Running,
        ]);
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => 'test_result_updated',
            'payload' => ['output' => 'Tests: 5 passed', 'status' => 'passed'],
        ]);

        $response = $this->getJson(route('runs.progress', $run));

        $response->assertOk();
        $response->assertJsonPath('live.current_stage', 'verify');
        $response->assertJsonPath('live.test_output', 'Tests: 5 passed');
        $response->assertJsonPath('live.test_status', 'passed');
    }

    public function test_progress_includes_release_steps(): void
    {
        $run = $this->createRunningRun();
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Release,
            'status' => StageStatus::Running,
        ]);
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => 'release_progress_updated',
            'payload' => ['step' => 'committed', 'detail' => 'feat: add feature'],
        ]);
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => 'release_progress_updated',
            'payload' => ['step' => 'pr_created', 'detail' => 'https://github.com/test/repo/pull/1'],
        ]);

        $response = $this->getJson(route('runs.progress', $run));

        $response->assertOk();
        $response->assertJsonPath('live.current_stage', 'release');
        $response->assertJsonCount(2, 'live.release_steps');
        $response->assertJsonPath('live.release_steps.0.step', 'committed');
        $response->assertJsonPath('live.release_steps.1.step', 'pr_created');
    }

    public function test_progress_returns_stuck_state(): void
    {
        $source = Source::factory()->create(['type' => SourceType::GitHub, 'is_active' => true]);
        $issue = Issue::factory()->create(['source_id' => $source->id, 'status' => IssueStatus::Stuck]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Stuck,
            'stuck_state' => \App\Enums\StuckState::IterationCap,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Stuck,
        ]);

        $response = $this->getJson(route('runs.progress', $run));

        $response->assertOk();
        $response->assertJsonPath('run_status', 'stuck');
        $response->assertJsonPath('stuck_state', 'iteration_cap');
    }

    public function test_issue_view_shows_stage_pipeline(): void
    {
        $source = Source::factory()->create(['type' => SourceType::GitHub, 'is_active' => true]);
        $issue = Issue::factory()->create(['source_id' => $source->id, 'status' => IssueStatus::InProgress]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::Completed,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertSee('stage-pipeline');
        $response->assertSee('Preflight');
        $response->assertSee('Implement');
        $response->assertSee('Verify');
        $response->assertSee('Release');
    }

    public function test_issue_view_includes_polling_script_for_active_run(): void
    {
        $source = Source::factory()->create(['type' => SourceType::GitHub, 'is_active' => true]);
        $issue = Issue::factory()->create(['source_id' => $source->id, 'status' => IssueStatus::InProgress]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertSee('progressUrl');
        $response->assertSee('visibilitychange');
    }

    public function test_issue_view_shows_live_diff_panel_during_implement(): void
    {
        $source = Source::factory()->create(['type' => SourceType::GitHub, 'is_active' => true]);
        $issue = Issue::factory()->create(['source_id' => $source->id, 'status' => IssueStatus::InProgress]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);

        $response = $this->get(route('issues.show', $issue));

        $response->assertStatus(200);
        $response->assertSee('live-diff-panel');
        $response->assertSee('Live Implementation');
    }

    public function test_issue_view_shows_live_test_panel_during_verify(): void
    {
        $source = Source::factory()->create(['type' => SourceType::GitHub, 'is_active' => true]);
        $issue = Issue::factory()->create(['source_id' => $source->id, 'status' => IssueStatus::InProgress]);
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
        $response->assertSee('live-test-panel');
        $response->assertSee('Live Test Output');
    }
}
