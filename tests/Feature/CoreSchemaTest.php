<?php

namespace Tests\Feature;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\SourceType;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Enums\StuckState;
use App\Models\AutonomyConfig;
use App\Models\EscalationRule;
use App\Models\FilterRule;
use App\Models\Issue;
use App\Models\OauthToken;
use App\Models\ProviderConfig;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Models\StageEvent;
use Database\Seeders\DefaultAutonomyConfigSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_has_many_issues(): void
    {
        $source = Source::factory()->create(['type' => SourceType::GitHub]);
        $issue = Issue::factory()->create(['source_id' => $source->id]);

        $this->assertTrue($source->issues->contains($issue));
        $this->assertEquals(SourceType::GitHub, $source->type);
    }

    public function test_issue_belongs_to_source_and_has_runs(): void
    {
        $issue = Issue::factory()->create(['status' => IssueStatus::Queued]);
        $run = Run::factory()->create(['issue_id' => $issue->id]);

        $this->assertEquals($issue->source_id, $issue->source->id);
        $this->assertTrue($issue->runs->contains($run));
        $this->assertEquals(IssueStatus::Queued, $issue->status);
    }

    public function test_run_has_stages_and_stages_have_events(): void
    {
        $run = Run::factory()->create(['status' => RunStatus::Running]);
        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Implement,
            'status' => StageStatus::Running,
        ]);
        $event = StageEvent::factory()->create(['stage_id' => $stage->id]);

        $this->assertTrue($run->stages->contains($stage));
        $this->assertTrue($stage->events->contains($event));
        $this->assertEquals(StageName::Implement, $stage->name);
        $this->assertEquals(StageStatus::Running, $stage->status);
    }

    public function test_full_relationship_chain(): void
    {
        $source = Source::factory()->create();
        $issue = Issue::factory()->create(['source_id' => $source->id]);
        $run = Run::factory()->create(['issue_id' => $issue->id]);
        $stage = Stage::factory()->create(['run_id' => $run->id]);
        $event = StageEvent::factory()->create(['stage_id' => $stage->id]);

        $this->assertEquals(
            $source->id,
            $event->stage->run->issue->source->id
        );
    }

    public function test_run_stuck_state_enum(): void
    {
        $run = Run::factory()->create([
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::IterationCap,
        ]);

        $this->assertEquals(StuckState::IterationCap, $run->fresh()->stuck_state);
    }

    public function test_autonomy_config_factory_and_enums(): void
    {
        $config = AutonomyConfig::factory()->create([
            'scope' => AutonomyScope::Global,
            'level' => AutonomyLevel::Supervised,
        ]);

        $this->assertEquals(AutonomyScope::Global, $config->scope);
        $this->assertEquals(AutonomyLevel::Supervised, $config->level);
    }

    public function test_escalation_rule_factory(): void
    {
        $rule = EscalationRule::factory()->create();

        $this->assertIsArray($rule->condition);
        $this->assertInstanceOf(AutonomyLevel::class, $rule->target_level);
    }

    public function test_filter_rule_belongs_to_source(): void
    {
        $source = Source::factory()->create();
        $rule = FilterRule::factory()->create(['source_id' => $source->id]);

        $this->assertEquals($source->id, $rule->source->id);
        $this->assertEquals($rule->id, $source->filterRule->id);
    }

    public function test_provider_config_factory(): void
    {
        $config = ProviderConfig::factory()->create();

        $this->assertIsArray($config->settings);
    }

    public function test_oauth_token_belongs_to_source(): void
    {
        $source = Source::factory()->create();
        $token = OauthToken::factory()->create(['source_id' => $source->id]);

        $this->assertEquals($source->id, $token->source->id);
        $this->assertTrue($source->oauthTokens->contains($token));
    }

    public function test_repository_factory(): void
    {
        $repo = Repository::factory()->create();

        $this->assertNotEmpty($repo->name);
        $this->assertNotEmpty($repo->path);
    }

    public function test_issue_unique_constraint_on_source_and_external_id(): void
    {
        $source = Source::factory()->create();
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'EXT-123',
        ]);

        $this->expectException(QueryException::class);
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'EXT-123',
        ]);
    }

    public function test_default_autonomy_seeder(): void
    {
        $this->seed(DefaultAutonomyConfigSeeder::class);

        $config = AutonomyConfig::where('scope', AutonomyScope::Global)
            ->whereNull('scope_id')
            ->whereNull('stage')
            ->first();

        $this->assertNotNull($config);
        $this->assertEquals(AutonomyLevel::Supervised, $config->level);
    }
}
