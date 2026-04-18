<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Enums\StuckState;
use App\Events\RunStuck;
use App\Events\StageTransitioned;
use App\Jobs\ExecuteStageJob;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Stage;
use App\Models\StageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ExecuteStageJobTest extends TestCase
{
    use RefreshDatabase;

    private function runningStage(): Stage
    {
        $issue = Issue::factory()->create(['status' => IssueStatus::InProgress]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);

        return Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);
    }

    public function test_failed_handler_marks_stage_stuck_with_job_failed(): void
    {
        Event::fake([StageTransitioned::class, RunStuck::class]);

        $stage = $this->runningStage();
        $job = new ExecuteStageJob($stage);

        $job->failed(new RuntimeException('boom'));

        $stage->refresh();
        $this->assertEquals(StageStatus::Stuck, $stage->status);

        $run = $stage->run;
        $this->assertEquals(RunStatus::Stuck, $run->status);
        $this->assertEquals(StuckState::JobFailed, $run->stuck_state);

        $this->assertEquals(IssueStatus::Stuck, $run->issue->fresh()->status);

        $event = StageEvent::where('stage_id', $stage->id)
            ->where('type', 'stuck')
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertEquals(StuckState::JobFailed->value, $event->payload['stuck_state']);
        $this->assertEquals('boom', $event->payload['reason']);
        $this->assertEquals(RuntimeException::class, $event->payload['exception']);

        Event::assertDispatched(StageTransitioned::class);
        Event::assertDispatched(RunStuck::class);
    }

    public function test_failed_handler_survives_timeout_exception(): void
    {
        Event::fake([RunStuck::class]);

        $stage = $this->runningStage();
        $job = new ExecuteStageJob($stage);

        $job->failed(new MaxAttemptsExceededException('A job exceeded its maximum allowed attempts.'));

        $stage->refresh();
        $this->assertEquals(StageStatus::Stuck, $stage->status);
        Event::assertDispatched(RunStuck::class);
    }

    public function test_failed_handler_is_noop_when_stage_already_completed(): void
    {
        Event::fake([RunStuck::class]);

        $stage = $this->runningStage();
        $stage->update(['status' => StageStatus::Completed, 'completed_at' => now()]);

        (new ExecuteStageJob($stage))->failed(new RuntimeException('late'));

        $stage->refresh();
        $this->assertEquals(StageStatus::Completed, $stage->status);
        Event::assertNotDispatched(RunStuck::class);
    }

    public function test_failed_handler_is_noop_when_stage_deleted(): void
    {
        Event::fake([RunStuck::class]);

        $stage = $this->runningStage();
        $id = $stage->id;
        $stage->delete();

        (new ExecuteStageJob($stage))->failed(new RuntimeException('gone'));

        $this->assertNull(Stage::find($id));
        Event::assertNotDispatched(RunStuck::class);
    }

    public function test_timeout_config_reflects_env_override(): void
    {
        config()->set('relay.orchestrator.stage_job_timeout', 900);

        $stage = $this->runningStage();
        $this->assertSame(900, (new ExecuteStageJob($stage))->timeout());
    }

    public function test_tries_is_one_so_queue_does_not_silently_retry_agent_work(): void
    {
        $stage = $this->runningStage();
        $this->assertSame(1, (new ExecuteStageJob($stage))->tries);
    }
}
