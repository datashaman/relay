<?php

namespace Tests\Feature;

use App\Enums\AutonomyLevel;
use App\Enums\StageName;
use App\Enums\StuckState;
use App\Models\Run;
use App\Models\Stage;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PushNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PushNotificationService;
    }

    public function test_should_notify_returns_true_for_manual(): void
    {
        $stage = Stage::factory()->make();

        $this->assertTrue($this->service->shouldNotify($stage, AutonomyLevel::Manual));
    }

    public function test_should_notify_returns_true_for_supervised(): void
    {
        $stage = Stage::factory()->make();

        $this->assertTrue($this->service->shouldNotify($stage, AutonomyLevel::Supervised));
    }

    public function test_should_notify_returns_false_for_assisted(): void
    {
        $stage = Stage::factory()->make();

        $this->assertFalse($this->service->shouldNotify($stage, AutonomyLevel::Assisted));
    }

    public function test_should_notify_returns_false_for_autonomous(): void
    {
        $stage = Stage::factory()->make();

        $this->assertFalse($this->service->shouldNotify($stage, AutonomyLevel::Autonomous));
    }

    public function test_notify_stuck_sends_notification_with_correct_data(): void
    {
        config(['nativephp-internal.running' => true]);
        Http::fake([
            '*notification*' => Http::response(['reference' => 'ref-1']),
            '*' => Http::response([]),
        ]);

        $run = Run::factory()->create(['stuck_state' => StuckState::IterationCap]);

        $this->service->notifyStuck($run);

        Http::assertSent(function ($request) use ($run) {
            return str_contains($request->url(), 'notification')
                && $request['title'] === 'Pipeline Stuck'
                && str_contains($request['body'], 'iteration_cap')
                && $request['event'] === 'run.stuck.'.$run->id;
        });
    }

    public function test_notify_approval_needed_sends_notification(): void
    {
        config(['nativephp-internal.running' => true]);
        Http::fake([
            '*notification*' => Http::response(['reference' => 'ref-2']),
            '*' => Http::response([]),
        ]);

        $stage = Stage::factory()->create(['name' => StageName::Preflight]);

        $this->service->notifyApprovalNeeded($stage);

        Http::assertSent(function ($request) use ($stage) {
            return str_contains($request->url(), 'notification')
                && $request['title'] === 'Approval Required'
                && str_contains($request['body'], 'Preflight')
                && $request['event'] === 'stage.approval.'.$stage->id;
        });
    }

    public function test_notify_is_noop_when_not_running_inside_nativephp(): void
    {
        config(['nativephp-internal.running' => false]);
        Http::fake();

        $run = Run::factory()->create(['stuck_state' => StuckState::IterationCap]);
        $stage = Stage::factory()->create(['name' => StageName::Preflight]);

        $this->service->notifyStuck($run);
        $this->service->notifyApprovalNeeded($stage);

        Http::assertNothingSent();
    }
}
