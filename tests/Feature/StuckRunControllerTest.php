<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Enums\StuckState;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StuckRunControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_stuck_runs(): void
    {
        $run = Run::factory()->create([
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::IterationCap,
            'stuck_unread' => true,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Stuck,
        ]);

        $response = $this->get(route('stuck.index'));

        $response->assertOk();
        $response->assertSee('Iteration Cap');
    }

    public function test_index_clears_unread_flag(): void
    {
        $run = Run::factory()->create([
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::IterationCap,
            'stuck_unread' => true,
        ]);

        $this->get(route('stuck.index'));

        $this->assertFalse($run->fresh()->stuck_unread);
    }

    public function test_index_empty_when_no_stuck_runs(): void
    {
        $response = $this->get(route('stuck.index'));

        $response->assertOk();
        $response->assertSee('No stuck issues');
    }

    public function test_show_guidance_for_stuck_run(): void
    {
        $run = Run::factory()->create([
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::AgentUncertain,
        ]);
        Stage::factory()->create(['run_id' => $run->id]);

        $response = $this->get(route('stuck.guidance', $run));

        $response->assertOk();
        $response->assertSee('Give Guidance');
    }

    public function test_show_guidance_404_for_non_stuck_run(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);

        $response = $this->get(route('stuck.guidance', $run));

        $response->assertNotFound();
    }

    public function test_submit_guidance_requires_text(): void
    {
        $run = Run::factory()->create([
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::IterationCap,
        ]);
        Stage::factory()->create(['run_id' => $run->id]);

        $response = $this->post(route('stuck.submit-guidance', $run), ['guidance' => '']);

        $response->assertSessionHasErrors('guidance');
    }

    public function test_submit_guidance_resumes_run(): void
    {
        Queue::fake();

        $run = Run::factory()->create([
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::IterationCap,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Stuck,
        ]);

        $response = $this->post(route('stuck.submit-guidance', $run), [
            'guidance' => 'Try using a different algorithm.',
        ]);

        $response->assertRedirect(route('stuck.index'));
        $this->assertEquals(RunStatus::Running, $run->fresh()->status);
    }

    public function test_restart_resumes_stuck_run(): void
    {
        Queue::fake();

        $run = Run::factory()->create([
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::Timeout,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Stuck,
        ]);

        $response = $this->post(route('stuck.restart', $run));

        $response->assertRedirect(route('stuck.index'));
        $this->assertEquals(RunStatus::Running, $run->fresh()->status);
    }

    public function test_restart_rejects_non_stuck_run(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);

        $response = $this->post(route('stuck.restart', $run));

        $response->assertStatus(422);
    }
}
