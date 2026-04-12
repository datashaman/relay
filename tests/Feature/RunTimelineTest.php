<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Models\Run;
use App\Models\Stage;
use App\Models\StageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_shows_run_with_stages_and_events(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running, 'iteration' => 1]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::Completed,
            'iteration' => 1,
        ]);
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => 'started',
            'actor' => 'system',
            'payload' => ['autonomy_level' => 'supervised'],
        ]);

        $response = $this->get(route('runs.timeline', $run));

        $response->assertOk();
        $response->assertSee('Run Timeline');
        $response->assertSee('Iteration 1');
        $response->assertSee('Preflight');
    }

    public function test_timeline_shows_iteration_badge_when_bounced(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running, 'iteration' => 3]);

        $response = $this->get(route('runs.timeline', $run));

        $response->assertOk();
        $response->assertSee('↺ 3');
    }

    public function test_timeline_shows_failure_report_on_bounce(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running, 'iteration' => 2]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Bounced,
            'iteration' => 1,
        ]);
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => 'bounced',
            'actor' => 'system',
            'payload' => ['failure_report' => ['Test testLogin failed: assertion error at line 42']],
        ]);

        $response = $this->get(route('runs.timeline', $run));

        $response->assertOk();
        $response->assertSee('Failure Report');
        $response->assertSee('Test testLogin failed');
    }

    public function test_timeline_shows_pr_link(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Completed]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Release,
            'status' => StageStatus::Completed,
            'iteration' => 1,
        ]);
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => 'release_complete',
            'actor' => 'release_agent',
            'payload' => ['pr_url' => 'https://github.com/org/repo/pull/42', 'summary' => 'Merged'],
        ]);

        $response = $this->get(route('runs.timeline', $run));

        $response->assertOk();
        $response->assertSee('https://github.com/org/repo/pull/42');
        $response->assertSee('Pull Request');
    }

    public function test_timeline_shows_implement_files_changed(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Completed,
            'iteration' => 1,
        ]);
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => 'implement_complete',
            'actor' => 'implement_agent',
            'payload' => [
                'summary' => 'Added login feature',
                'files_changed' => ['app/Http/Controllers/AuthController.php', 'routes/web.php'],
            ],
        ]);

        $response = $this->get(route('runs.timeline', $run));

        $response->assertOk();
        $response->assertSee('Added login feature');
        $response->assertSee('Files changed (2)');
    }

    public function test_timeline_shows_multiple_iterations(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running, 'iteration' => 2]);

        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Bounced,
            'iteration' => 1,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Bounced,
            'iteration' => 1,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
            'iteration' => 2,
        ]);

        $response = $this->get(route('runs.timeline', $run));

        $response->assertOk();
        $response->assertSee('Iteration 1');
        $response->assertSee('Iteration 2');
    }

    public function test_timeline_shows_tool_calls(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
            'iteration' => 1,
        ]);
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => 'tool_call',
            'actor' => 'implement_agent',
            'payload' => ['tool' => 'write_file', 'success' => true],
        ]);

        $response = $this->get(route('runs.timeline', $run));

        $response->assertOk();
        $response->assertSee('write_file');
    }

    public function test_timeline_shows_guidance(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
            'iteration' => 1,
        ]);
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => 'guidance_received',
            'actor' => 'user',
            'payload' => ['guidance' => 'Try using the repository pattern instead'],
        ]);

        $response = $this->get(route('runs.timeline', $run));

        $response->assertOk();
        $response->assertSee('Try using the repository pattern instead');
    }
}
