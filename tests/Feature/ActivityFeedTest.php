<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Models\StageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_feed_displays_events(): void
    {
        $source = Source::factory()->create(['external_account' => 'acme/repo']);
        $issue = Issue::factory()->create(['source_id' => $source->id, 'title' => 'Fix login bug']);
        $run = Run::factory()->create(['issue_id' => $issue->id, 'status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
            'iteration' => 1,
        ]);
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => 'started',
            'actor' => 'system',
        ]);

        $response = $this->get(route('activity.index'));

        $response->assertOk();
        $response->assertSee('Activity Feed');
        $response->assertSee('Stage started');
        $response->assertSee('Fix login bug');
        $response->assertSee('acme/repo');
    }

    public function test_activity_feed_shows_empty_state(): void
    {
        $response = $this->get(route('activity.index'));

        $response->assertOk();
        $response->assertSee('No activity yet');
    }

    public function test_activity_feed_filters_by_source(): void
    {
        $source1 = Source::factory()->create(['external_account' => 'org/repo1']);
        $source2 = Source::factory()->create(['external_account' => 'org/repo2']);
        $issue1 = Issue::factory()->create(['source_id' => $source1->id, 'title' => 'Issue One']);
        $issue2 = Issue::factory()->create(['source_id' => $source2->id, 'title' => 'Issue Two']);
        $run1 = Run::factory()->create(['issue_id' => $issue1->id]);
        $run2 = Run::factory()->create(['issue_id' => $issue2->id]);
        $stage1 = Stage::factory()->create(['run_id' => $run1->id, 'name' => StageName::Preflight, 'iteration' => 1]);
        $stage2 = Stage::factory()->create(['run_id' => $run2->id, 'name' => StageName::Implement, 'iteration' => 1]);
        StageEvent::factory()->create(['stage_id' => $stage1->id, 'type' => 'started', 'actor' => 'system']);
        StageEvent::factory()->create(['stage_id' => $stage2->id, 'type' => 'started', 'actor' => 'system']);

        $response = $this->get(route('activity.index', ['source' => $source1->id]));

        $response->assertOk();
        $response->assertSee('Issue One');
        $response->assertDontSee('Issue Two');
    }

    public function test_activity_feed_filters_by_stage(): void
    {
        $run = Run::factory()->create();
        $preflight = Stage::factory()->create(['run_id' => $run->id, 'name' => StageName::Preflight, 'iteration' => 1]);
        $implement = Stage::factory()->create(['run_id' => $run->id, 'name' => StageName::Implement, 'iteration' => 1]);
        StageEvent::factory()->create(['stage_id' => $preflight->id, 'type' => 'started', 'actor' => 'system']);
        StageEvent::factory()->create(['stage_id' => $implement->id, 'type' => 'implement_started', 'actor' => 'implement_agent']);

        $response = $this->get(route('activity.index', ['stage' => 'implement']));

        $response->assertOk();
        $response->assertSee('Implementation started');
        $response->assertDontSee('Stage started');
    }

    public function test_activity_feed_filters_by_actor(): void
    {
        $run = Run::factory()->create();
        $stage = Stage::factory()->create(['run_id' => $run->id, 'name' => StageName::Implement, 'iteration' => 1]);
        StageEvent::factory()->create(['stage_id' => $stage->id, 'type' => 'started', 'actor' => 'system']);
        StageEvent::factory()->create(['stage_id' => $stage->id, 'type' => 'guidance_received', 'actor' => 'user', 'payload' => ['guidance' => 'Try another approach']]);

        $response = $this->get(route('activity.index', ['actor' => 'user']));

        $response->assertOk();
        $response->assertSee('Guidance received');
        $response->assertDontSee('Stage started');
    }

    public function test_activity_feed_deep_links_to_timeline(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create(['run_id' => $run->id, 'name' => StageName::Verify, 'iteration' => 1]);
        StageEvent::factory()->create(['stage_id' => $stage->id, 'type' => 'started', 'actor' => 'system']);

        $response = $this->get(route('activity.index'));

        $response->assertOk();
        $response->assertSee(route('runs.timeline', $run));
        $response->assertSee('View →');
    }

    public function test_stuck_chip_shows_in_topbar(): void
    {
        Run::factory()->create(['status' => RunStatus::Stuck]);
        Run::factory()->create(['status' => RunStatus::Stuck]);

        $response = $this->get(route('activity.index'));

        $response->assertOk();
        $response->assertSee('2 Stuck');
    }

    public function test_stuck_chip_hidden_when_no_stuck_runs(): void
    {
        Run::factory()->create(['status' => RunStatus::Running]);

        $response = $this->get(route('activity.index'));

        $response->assertOk();
        $response->assertDontSee(' stuck</span>');
    }

    public function test_activity_feed_shows_stage_badge(): void
    {
        $run = Run::factory()->create();
        $stage = Stage::factory()->create(['run_id' => $run->id, 'name' => StageName::Verify, 'iteration' => 1]);
        StageEvent::factory()->create(['stage_id' => $stage->id, 'type' => 'started', 'actor' => 'system']);

        $response = $this->get(route('activity.index'));

        $response->assertOk();
        $response->assertSee('Verify');
    }

    public function test_activity_feed_shows_iteration_count(): void
    {
        $run = Run::factory()->create(['iteration' => 3]);
        $stage = Stage::factory()->create(['run_id' => $run->id, 'name' => StageName::Implement, 'iteration' => 3]);
        StageEvent::factory()->create(['stage_id' => $stage->id, 'type' => 'started', 'actor' => 'system']);

        $response = $this->get(route('activity.index'));

        $response->assertOk();
        $response->assertSee('↺ 3');
    }
}
