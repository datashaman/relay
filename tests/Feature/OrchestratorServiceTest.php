<?php

namespace Tests\Feature;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Enums\StuckState;
use App\Events\RunStuck;
use App\Events\StageTransitioned;
use App\Jobs\ExecuteStageJob;
use App\Models\AutonomyConfig;
use App\Models\EscalationRule;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Services\OrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrchestratorServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrchestratorService $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orchestrator = app(OrchestratorService::class);
    }

    private function setGlobalAutonomy(AutonomyLevel $level): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => $level,
        ]);
    }

    // --- startRun ---

    public function test_start_run_creates_run_with_running_status(): void
    {
        Queue::fake();
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);

        $run = $this->orchestrator->startRun($issue);

        $this->assertEquals(RunStatus::Running, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertEquals($issue->id, $run->issue_id);
    }

    public function test_start_run_transitions_issue_to_in_progress(): void
    {
        Queue::fake();
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);

        $this->orchestrator->startRun($issue);

        $this->assertEquals(IssueStatus::InProgress, $issue->fresh()->status);
    }

    public function test_start_run_creates_preflight_stage(): void
    {
        Queue::fake();
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);

        $run = $this->orchestrator->startRun($issue);

        $stage = $run->stages()->first();
        $this->assertNotNull($stage);
        $this->assertEquals(StageName::Preflight, $stage->name);
    }

    public function test_start_run_auto_advances_when_autonomous(): void
    {
        Queue::fake();
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);

        $run = $this->orchestrator->startRun($issue);

        $stage = $run->stages()->first();
        $this->assertEquals(StageStatus::Running, $stage->status);
        Queue::assertPushed(ExecuteStageJob::class, function ($job) use ($stage) {
            return $job->stage->id === $stage->id;
        });
    }

    public function test_start_run_pauses_for_approval_when_supervised(): void
    {
        Queue::fake();
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $this->setGlobalAutonomy(AutonomyLevel::Supervised);

        $run = $this->orchestrator->startRun($issue);

        $stage = $run->stages()->first();
        $this->assertEquals(StageStatus::AwaitingApproval, $stage->status);
        Queue::assertNotPushed(ExecuteStageJob::class);
    }

    public function test_start_run_pauses_for_approval_when_manual(): void
    {
        Queue::fake();
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $this->setGlobalAutonomy(AutonomyLevel::Manual);

        $run = $this->orchestrator->startRun($issue);

        $stage = $run->stages()->first();
        $this->assertEquals(StageStatus::AwaitingApproval, $stage->status);
    }

    public function test_start_run_auto_advances_when_assisted(): void
    {
        Queue::fake();
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $this->setGlobalAutonomy(AutonomyLevel::Assisted);

        $run = $this->orchestrator->startRun($issue);

        $stage = $run->stages()->first();
        $this->assertEquals(StageStatus::Running, $stage->status);
        Queue::assertPushed(ExecuteStageJob::class);
    }

    // --- Events emitted ---

    public function test_start_records_stage_event(): void
    {
        Queue::fake();
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);

        $run = $this->orchestrator->startRun($issue);

        $stage = $run->stages()->first();
        $event = StageEvent::where('stage_id', $stage->id)->where('type', 'started')->first();
        $this->assertNotNull($event);
        $this->assertEquals('system', $event->actor);
        $this->assertEquals('autonomous', $event->payload['autonomy_level']);
    }

    public function test_awaiting_approval_records_event(): void
    {
        Queue::fake();
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $this->setGlobalAutonomy(AutonomyLevel::Supervised);

        $run = $this->orchestrator->startRun($issue);

        $stage = $run->stages()->first();
        $event = StageEvent::where('stage_id', $stage->id)->where('type', 'awaiting_approval')->first();
        $this->assertNotNull($event);
        $this->assertEquals('supervised', $event->payload['autonomy_level']);
    }

    // --- pause ---

    public function test_pause_sets_awaiting_approval_status(): void
    {
        $stage = Stage::factory()->create(['status' => StageStatus::Running]);

        $this->orchestrator->pause($stage);

        $this->assertEquals(StageStatus::AwaitingApproval, $stage->fresh()->status);
        $event = StageEvent::where('stage_id', $stage->id)->where('type', 'paused')->first();
        $this->assertNotNull($event);
    }

    // --- resume ---

    public function test_resume_sets_running_status_and_dispatches_job(): void
    {
        Queue::fake();
        $stage = Stage::factory()->create(['status' => StageStatus::AwaitingApproval]);

        $this->orchestrator->resume($stage);

        $this->assertEquals(StageStatus::Running, $stage->fresh()->status);
        Queue::assertPushed(ExecuteStageJob::class);
        $event = StageEvent::where('stage_id', $stage->id)->where('type', 'resumed')->first();
        $this->assertNotNull($event);
        $this->assertEquals('user', $event->actor);
    }

    public function test_resume_preserves_original_started_at(): void
    {
        Queue::fake();
        $started = now()->subMinutes(10);
        $stage = Stage::factory()->create([
            'status' => StageStatus::AwaitingApproval,
            'started_at' => $started,
        ]);

        $this->orchestrator->resume($stage);

        $this->assertEquals(
            $started->toDateTimeString(),
            $stage->fresh()->started_at->toDateTimeString(),
        );
    }

    // --- complete ---

    public function test_complete_marks_stage_completed(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->complete($stage);

        $this->assertEquals(StageStatus::Completed, $stage->fresh()->status);
        $this->assertNotNull($stage->fresh()->completed_at);
    }

    public function test_complete_advances_to_next_stage(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->complete($stage);

        $nextStage = $run->stages()->where('name', StageName::Implement)->first();
        $this->assertNotNull($nextStage);
        $this->assertEquals(StageStatus::Running, $nextStage->status);
    }

    public function test_complete_release_completes_run(): void
    {
        Queue::fake();
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $issue = $run->issue;
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Release,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->complete($stage);

        $this->assertEquals(RunStatus::Completed, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->completed_at);
        $this->assertEquals(IssueStatus::Completed, $issue->fresh()->status);
    }

    public function test_complete_stage_order_preflight_to_implement(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->complete($stage);

        $this->assertNotNull($run->stages()->where('name', StageName::Implement)->first());
    }

    public function test_complete_stage_order_implement_to_verify(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->complete($stage);

        $this->assertNotNull($run->stages()->where('name', StageName::Verify)->first());
    }

    public function test_complete_stage_order_verify_to_release(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->complete($stage);

        $this->assertNotNull($run->stages()->where('name', StageName::Release)->first());
    }

    // --- fail ---

    public function test_fail_marks_stage_and_run_failed(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $issue = $run->issue;
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->fail($stage, 'Agent crashed');

        $this->assertEquals(StageStatus::Failed, $stage->fresh()->status);
        $this->assertNotNull($stage->fresh()->completed_at);
        $this->assertEquals(RunStatus::Failed, $run->fresh()->status);
        $this->assertEquals(IssueStatus::Failed, $issue->fresh()->status);

        $event = StageEvent::where('stage_id', $stage->id)->where('type', 'failed')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Agent crashed', $event->payload['reason']);
    }

    public function test_fail_without_reason(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->fail($stage);

        $event = StageEvent::where('stage_id', $stage->id)->where('type', 'failed')->first();
        $this->assertNull($event->payload);
    }

    // --- bounce ---

    public function test_bounce_marks_stage_bounced(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $run = Run::factory()->create(['status' => RunStatus::Running, 'iteration' => 1]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->bounce($stage, ['test_failures' => ['test_foo']]);

        $this->assertEquals(StageStatus::Bounced, $stage->fresh()->status);
        $this->assertNotNull($stage->fresh()->completed_at);
    }

    public function test_bounce_creates_previous_stage_with_incremented_iteration(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $run = Run::factory()->create(['status' => RunStatus::Running, 'iteration' => 1]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->bounce($stage, ['test_failures' => ['test_foo']]);

        $newStage = $run->stages()
            ->where('name', StageName::Implement)
            ->latest('id')
            ->first();
        $this->assertNotNull($newStage);
        $this->assertEquals(2, $run->fresh()->iteration);
        $this->assertEquals(2, $newStage->iteration);
    }

    public function test_bounce_records_failure_report(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $run = Run::factory()->create(['status' => RunStatus::Running, 'iteration' => 0]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Running,
        ]);

        $report = ['test_failures' => ['test_foo', 'test_bar']];
        $this->orchestrator->bounce($stage, $report);

        $bounceEvent = StageEvent::where('stage_id', $stage->id)
            ->where('type', 'bounced')
            ->first();
        $this->assertNotNull($bounceEvent);
        $this->assertEquals($report, $bounceEvent->payload['failure_report']);

        $newStage = $run->stages()->where('name', StageName::Implement)->latest('id')->first();
        $receiveEvent = StageEvent::where('stage_id', $newStage->id)
            ->where('type', 'implement.iteration.1')
            ->first();
        $this->assertNotNull($receiveEvent);
        $this->assertEquals($report, $receiveEvent->payload['failure_report']);
    }

    public function test_bounce_from_preflight_fails_run(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->bounce($stage);

        $this->assertEquals(RunStatus::Failed, $run->fresh()->status);
    }

    // --- escalation integration ---

    public function test_escalation_rule_forces_approval(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $issue = Issue::factory()->create([
            'status' => IssueStatus::Accepted,
            'labels' => ['security'],
        ]);

        EscalationRule::create([
            'name' => 'Security review',
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'target_level' => AutonomyLevel::Manual,
            'scope' => AutonomyScope::Global,
            'order' => 0,
            'is_enabled' => true,
        ]);

        $run = $this->orchestrator->startRun($issue);

        $stage = $run->stages()->first();
        $this->assertEquals(StageStatus::AwaitingApproval, $stage->status);
        Queue::assertNotPushed(ExecuteStageJob::class);
    }

    // --- broadcasting ---

    public function test_stage_transitions_broadcast_events(): void
    {
        Event::fake([StageTransitioned::class]);
        Queue::fake();
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);

        $this->orchestrator->startRun($issue);

        Event::assertDispatched(StageTransitioned::class);
    }

    public function test_pause_broadcasts_event(): void
    {
        Event::fake([StageTransitioned::class]);
        $stage = Stage::factory()->create(['status' => StageStatus::Running]);

        $this->orchestrator->pause($stage);

        Event::assertDispatched(StageTransitioned::class);
    }

    public function test_resume_broadcasts_event(): void
    {
        Event::fake([StageTransitioned::class]);
        Queue::fake();
        $stage = Stage::factory()->create(['status' => StageStatus::AwaitingApproval]);

        $this->orchestrator->resume($stage);

        Event::assertDispatched(StageTransitioned::class);
    }

    public function test_complete_broadcasts_event(): void
    {
        Event::fake([StageTransitioned::class]);
        Queue::fake();
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Release,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->complete($stage);

        Event::assertDispatched(StageTransitioned::class);
    }

    public function test_fail_broadcasts_event(): void
    {
        Event::fake([StageTransitioned::class]);
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->fail($stage);

        Event::assertDispatched(StageTransitioned::class);
    }

    public function test_broadcast_event_contains_stage_data(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);

        $run = $this->orchestrator->startRun($issue);
        $stage = $run->stages()->first();

        $event = new StageTransitioned($stage);
        $data = $event->broadcastWith();

        $this->assertEquals($stage->id, $data['stage_id']);
        $this->assertEquals($run->id, $data['run_id']);
        $this->assertEquals('preflight', $data['name']);
        $this->assertEquals('running', $data['status']);
    }

    public function test_broadcast_channel_scoped_to_run(): void
    {
        $stage = Stage::factory()->create();
        $event = new StageTransitioned($stage);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertEquals('run.'.$stage->run_id, $channels[0]->name);
    }

    // --- full pipeline flow ---

    public function test_full_pipeline_autonomous_flow(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);

        $run = $this->orchestrator->startRun($issue);
        $preflightStage = $run->stages()->where('name', StageName::Preflight)->first();
        $this->assertEquals(StageStatus::Running, $preflightStage->status);

        $this->orchestrator->complete($preflightStage);
        $implementStage = $run->stages()->where('name', StageName::Implement)->first();
        $this->assertNotNull($implementStage);
        $this->assertEquals(StageStatus::Running, $implementStage->status);

        $this->orchestrator->complete($implementStage);
        $verifyStage = $run->stages()->where('name', StageName::Verify)->first();
        $this->assertNotNull($verifyStage);

        $this->orchestrator->complete($verifyStage);
        $releaseStage = $run->stages()->where('name', StageName::Release)->first();
        $this->assertNotNull($releaseStage);

        $this->orchestrator->complete($releaseStage);

        $this->assertEquals(RunStatus::Completed, $run->fresh()->status);
        $this->assertEquals(IssueStatus::Completed, $issue->fresh()->status);
    }

    public function test_supervised_flow_requires_approvals(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Supervised);
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);

        $run = $this->orchestrator->startRun($issue);
        $preflightStage = $run->stages()->where('name', StageName::Preflight)->first();
        $this->assertEquals(StageStatus::AwaitingApproval, $preflightStage->status);

        $this->orchestrator->resume($preflightStage);
        $this->assertEquals(StageStatus::Running, $preflightStage->fresh()->status);

        $this->orchestrator->complete($preflightStage);
        $implementStage = $run->stages()->where('name', StageName::Implement)->first();
        $this->assertEquals(StageStatus::AwaitingApproval, $implementStage->status);
    }

    // --- iteration cap ---

    public function test_bounce_triggers_stuck_when_iteration_cap_reached(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        config(['relay.iteration_cap' => 3]);

        $run = Run::factory()->create(['status' => RunStatus::Running, 'iteration' => 2]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->bounce($stage, ['test failed']);

        $run->refresh();
        $this->assertEquals(RunStatus::Stuck, $run->status);
        $this->assertEquals(StuckState::IterationCap, $run->stuck_state);
        $this->assertEquals(3, $run->iteration);

        $event = StageEvent::where('stage_id', $stage->id)
            ->where('type', 'stuck')
            ->first();
        $this->assertNotNull($event);
        $this->assertEquals('iteration_cap', $event->payload['stuck_state']);
        $this->assertEquals(3, $event->payload['iteration']);
        $this->assertEquals(3, $event->payload['cap']);
    }

    public function test_bounce_does_not_create_new_stage_when_capped(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        config(['relay.iteration_cap' => 2]);

        $run = Run::factory()->create(['status' => RunStatus::Running, 'iteration' => 1]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->bounce($stage, ['test failed']);

        $implementStages = $run->stages()->where('name', StageName::Implement)->count();
        $this->assertEquals(0, $implementStages);
        Queue::assertNotPushed(ExecuteStageJob::class);
    }

    public function test_bounce_below_cap_proceeds_normally(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        config(['relay.iteration_cap' => 5]);

        $run = Run::factory()->create(['status' => RunStatus::Running, 'iteration' => 1]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->bounce($stage, ['test failed']);

        $run->refresh();
        $this->assertEquals(RunStatus::Running, $run->status);
        $this->assertNull($run->stuck_state);

        $newStage = $run->stages()->where('name', StageName::Implement)->latest('id')->first();
        $this->assertNotNull($newStage);
        $this->assertEquals(2, $newStage->iteration);
    }

    public function test_iteration_cap_defaults_to_five(): void
    {
        $this->assertEquals(5, config('relay.iteration_cap'));
    }

    // --- stage execution dispatched to queue ---

    public function test_stage_execution_dispatched_to_queue(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $issue = Issue::factory()->create(['status' => IssueStatus::Accepted]);

        $this->orchestrator->startRun($issue);

        Queue::assertPushed(ExecuteStageJob::class, 1);
    }

    public function test_resume_dispatches_to_queue(): void
    {
        Queue::fake();
        $stage = Stage::factory()->create(['status' => StageStatus::AwaitingApproval]);

        $this->orchestrator->resume($stage, ['key' => 'value']);

        Queue::assertPushed(ExecuteStageJob::class, function ($job) {
            return $job->context === ['key' => 'value'];
        });
    }

    // --- events persisted for all state changes ---

    public function test_all_lifecycle_events_persisted(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);
        $run = Run::factory()->create(['status' => RunStatus::Running, 'iteration' => 0]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->bounce($stage, ['reason' => 'tests failed']);

        $bounceEvent = StageEvent::where('stage_id', $stage->id)->where('type', 'bounced')->first();
        $this->assertNotNull($bounceEvent);

        $newStage = $run->stages()->where('name', StageName::Implement)->latest('id')->first();

        $types = StageEvent::where('stage_id', $newStage->id)
            ->pluck('type')
            ->toArray();
        $this->assertContains('implement.iteration.1', $types);
        $this->assertContains('started', $types);
    }

    // --- markStuck ---

    public function test_mark_stuck_sets_run_and_stage_and_issue_to_stuck(): void
    {
        Queue::fake();
        Event::fake([RunStuck::class, StageTransitioned::class]);

        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->markStuck($stage, StuckState::Timeout, ['reason' => 'no progress']);

        $run->refresh();
        $stage->refresh();

        $this->assertEquals(RunStatus::Stuck, $run->status);
        $this->assertEquals(StuckState::Timeout, $run->stuck_state);
        $this->assertTrue($run->stuck_unread);
        $this->assertEquals(StageStatus::Stuck, $stage->status);
        $this->assertNotNull($stage->completed_at);
        $this->assertEquals(IssueStatus::Stuck, $run->issue->fresh()->status);
    }

    public function test_mark_stuck_records_event_and_broadcasts(): void
    {
        Queue::fake();
        Event::fake([RunStuck::class, StageTransitioned::class]);

        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->markStuck($stage, StuckState::AgentUncertain, ['confidence' => 0.2]);

        $event = StageEvent::where('stage_id', $stage->id)->where('type', 'stuck')->first();
        $this->assertNotNull($event);
        $this->assertEquals('agent_uncertain', $event->payload['stuck_state']);
        $this->assertEquals(0.2, $event->payload['confidence']);

        Event::assertDispatched(RunStuck::class);
        Event::assertDispatched(StageTransitioned::class);
    }

    public function test_mark_stuck_with_external_blocker(): void
    {
        Queue::fake();
        Event::fake([RunStuck::class, StageTransitioned::class]);

        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);

        $this->orchestrator->markStuck($stage, StuckState::ExternalBlocker, ['missing' => 'git credentials']);

        $run->refresh();
        $this->assertEquals(StuckState::ExternalBlocker, $run->stuck_state);
    }

    // --- giveGuidance ---

    public function test_give_guidance_resumes_stuck_run(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);

        $run = Run::factory()->create([
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::IterationCap,
            'stuck_unread' => true,
        ]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Stuck,
        ]);

        $this->orchestrator->giveGuidance($run, 'Try a different approach to the database query.');

        $run->refresh();
        $this->assertEquals(RunStatus::Running, $run->status);
        $this->assertNull($run->stuck_state);
        $this->assertFalse($run->stuck_unread);
        $this->assertEquals('Try a different approach to the database query.', $run->guidance);
        $this->assertEquals(IssueStatus::InProgress, $run->issue->fresh()->status);
    }

    public function test_give_guidance_creates_new_stage_and_dispatches(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);

        $run = Run::factory()->create([
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::IterationCap,
        ]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Stuck,
            'iteration' => 3,
        ]);

        $this->orchestrator->giveGuidance($run, 'Fix the null check.');

        $newStage = $run->stages()->where('id', '!=', $stage->id)->latest('id')->first();
        $this->assertNotNull($newStage);
        $this->assertEquals(StageName::Implement, $newStage->name);

        $guidanceEvent = StageEvent::where('stage_id', $newStage->id)
            ->where('type', 'guidance_received')
            ->first();
        $this->assertNotNull($guidanceEvent);
        $this->assertEquals('Fix the null check.', $guidanceEvent->payload['guidance']);

        Queue::assertPushed(ExecuteStageJob::class, function ($job) {
            return ($job->context['guidance'] ?? null) === 'Fix the null check.';
        });
    }

    public function test_give_guidance_preserves_preflight_doc(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);

        $run = Run::factory()->create([
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::AgentUncertain,
            'preflight_doc' => '# Original Doc',
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Stuck,
        ]);

        $this->orchestrator->giveGuidance($run, 'Clarification here.');

        $run->refresh();
        $this->assertEquals('# Original Doc', $run->preflight_doc);
    }

    // --- restart ---

    public function test_restart_clears_stuck_state_and_retries(): void
    {
        Queue::fake();
        $this->setGlobalAutonomy(AutonomyLevel::Autonomous);

        $run = Run::factory()->create([
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::Timeout,
            'stuck_unread' => true,
        ]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Verify,
            'status' => StageStatus::Stuck,
        ]);

        $this->orchestrator->restart($run);

        $run->refresh();
        $this->assertEquals(RunStatus::Running, $run->status);
        $this->assertNull($run->stuck_state);
        $this->assertFalse($run->stuck_unread);
        $this->assertEquals(IssueStatus::InProgress, $run->issue->fresh()->status);

        $newStage = $run->stages()->where('id', '!=', $stage->id)->latest('id')->first();
        $this->assertNotNull($newStage);
        $this->assertEquals(StageName::Verify, $newStage->name);

        $restartEvent = StageEvent::where('stage_id', $newStage->id)
            ->where('type', 'restarted')
            ->first();
        $this->assertNotNull($restartEvent);
        $this->assertEquals('user', $restartEvent->actor);

        Queue::assertPushed(ExecuteStageJob::class);
    }
}
