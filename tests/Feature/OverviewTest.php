<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\SourceType;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverviewTest extends TestCase
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

    private function createActiveRun(string $title, RunStatus $runStatus = RunStatus::Running): Run
    {
        $source = $this->createSource();
        $issue = Issue::factory()->create([
            'source_id' => $source->id,
            'status' => IssueStatus::InProgress,
            'title' => $title,
        ]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => $runStatus,
        ]);
        Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);

        return $run;
    }

    public function test_overview_shows_active_runs(): void
    {
        $this->createActiveRun('Fix the login bug');

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Fix the login bug');
    }

    public function test_overview_shows_empty_state_when_no_active_runs(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('No active runs.');
    }

    public function test_overview_excludes_queued_and_rejected_issues(): void
    {
        $source = $this->createSource();
        Issue::factory()->create([
            'source_id' => $source->id,
            'status' => IssueStatus::Queued,
            'title' => 'Queued issue should not appear',
        ]);
        Issue::factory()->create([
            'source_id' => $source->id,
            'status' => IssueStatus::Rejected,
            'title' => 'Rejected issue should not appear',
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('Queued issue should not appear');
        $response->assertDontSee('Rejected issue should not appear');
    }

    public function test_overview_surfaces_stuck_runs(): void
    {
        $this->createActiveRun('Stuck issue', RunStatus::Stuck);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Stuck issue');
        $response->assertSee('Needs You');
    }

    public function test_overview_nav_link_present(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Overview');
    }
}
