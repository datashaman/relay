<?php

namespace Tests\Feature;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\StageName;
use App\Models\AutonomyConfig;
use App\Models\Issue;
use App\Services\AutonomyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AutonomyResolverTest extends TestCase
{
    use RefreshDatabase;

    private AutonomyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AutonomyResolver;
    }

    public function test_four_autonomy_levels_exist(): void
    {
        $levels = AutonomyLevel::cases();
        $this->assertCount(4, $levels);
        $this->assertEquals('manual', AutonomyLevel::Manual->value);
        $this->assertEquals('supervised', AutonomyLevel::Supervised->value);
        $this->assertEquals('assisted', AutonomyLevel::Assisted->value);
        $this->assertEquals('autonomous', AutonomyLevel::Autonomous->value);
    }

    public function test_level_ordering(): void
    {
        $this->assertEquals(0, AutonomyLevel::Manual->order());
        $this->assertEquals(1, AutonomyLevel::Supervised->order());
        $this->assertEquals(2, AutonomyLevel::Assisted->order());
        $this->assertEquals(3, AutonomyLevel::Autonomous->order());
    }

    public function test_tighter_than_or_equal(): void
    {
        $this->assertTrue(AutonomyLevel::Manual->isTighterThanOrEqual(AutonomyLevel::Supervised));
        $this->assertTrue(AutonomyLevel::Supervised->isTighterThanOrEqual(AutonomyLevel::Supervised));
        $this->assertFalse(AutonomyLevel::Assisted->isTighterThanOrEqual(AutonomyLevel::Supervised));
    }

    public function test_looser_than_or_equal(): void
    {
        $this->assertTrue(AutonomyLevel::Autonomous->isLooserThanOrEqual(AutonomyLevel::Supervised));
        $this->assertTrue(AutonomyLevel::Supervised->isLooserThanOrEqual(AutonomyLevel::Supervised));
        $this->assertFalse(AutonomyLevel::Manual->isLooserThanOrEqual(AutonomyLevel::Supervised));
    }

    public function test_global_default_falls_back_to_supervised(): void
    {
        $this->assertEquals(AutonomyLevel::Supervised, $this->resolver->getGlobalDefault());
    }

    public function test_global_default_from_config_row(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Autonomous,
        ]);

        $this->assertEquals(AutonomyLevel::Autonomous, $this->resolver->getGlobalDefault());
    }

    public function test_resolve_returns_global_when_no_overrides(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Assisted,
        ]);

        $issue = Issue::factory()->create();
        $result = $this->resolver->resolve($issue->id, StageName::Implement);

        $this->assertEquals(AutonomyLevel::Assisted, $result);
    }

    public function test_resolve_stage_override_tightens_from_global(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Assisted,
        ]);

        AutonomyConfig::create([
            'scope' => AutonomyScope::Stage,
            'scope_id' => null,
            'stage' => StageName::Release,
            'level' => AutonomyLevel::Manual,
        ]);

        $issue = Issue::factory()->create();

        $this->assertEquals(AutonomyLevel::Manual, $this->resolver->resolve($issue->id, StageName::Release));
        $this->assertEquals(AutonomyLevel::Assisted, $this->resolver->resolve($issue->id, StageName::Implement));
    }

    public function test_resolve_issue_override_loosens_from_stage(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Assisted,
        ]);

        AutonomyConfig::create([
            'scope' => AutonomyScope::Stage,
            'scope_id' => null,
            'stage' => StageName::Release,
            'level' => AutonomyLevel::Manual,
        ]);

        $issue = Issue::factory()->create();

        AutonomyConfig::create([
            'scope' => AutonomyScope::Issue,
            'scope_id' => $issue->id,
            'stage' => StageName::Release,
            'level' => AutonomyLevel::Supervised,
        ]);

        $this->assertEquals(AutonomyLevel::Supervised, $this->resolver->resolve($issue->id, StageName::Release));
    }

    public function test_resolve_issue_all_stages_override(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Manual,
        ]);

        $issue = Issue::factory()->create();

        AutonomyConfig::create([
            'scope' => AutonomyScope::Issue,
            'scope_id' => $issue->id,
            'stage' => null,
            'level' => AutonomyLevel::Assisted,
        ]);

        $this->assertEquals(AutonomyLevel::Assisted, $this->resolver->resolve($issue->id, StageName::Preflight));
        $this->assertEquals(AutonomyLevel::Assisted, $this->resolver->resolve($issue->id, StageName::Implement));
    }

    public function test_resolve_issue_stage_specific_overrides_issue_global(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Manual,
        ]);

        $issue = Issue::factory()->create();

        AutonomyConfig::create([
            'scope' => AutonomyScope::Issue,
            'scope_id' => $issue->id,
            'stage' => null,
            'level' => AutonomyLevel::Assisted,
        ]);

        AutonomyConfig::create([
            'scope' => AutonomyScope::Issue,
            'scope_id' => $issue->id,
            'stage' => StageName::Release,
            'level' => AutonomyLevel::Autonomous,
        ]);

        $this->assertEquals(AutonomyLevel::Autonomous, $this->resolver->resolve($issue->id, StageName::Release));
        $this->assertEquals(AutonomyLevel::Assisted, $this->resolver->resolve($issue->id, StageName::Implement));
    }

    public function test_validate_stage_cannot_loosen_from_global(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Supervised,
        ]);

        $this->expectException(ValidationException::class);

        $this->resolver->validateAndSave(
            AutonomyScope::Stage,
            null,
            StageName::Implement,
            AutonomyLevel::Autonomous,
        );
    }

    public function test_validate_stage_can_tighten_from_global(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Supervised,
        ]);

        $config = $this->resolver->validateAndSave(
            AutonomyScope::Stage,
            null,
            StageName::Implement,
            AutonomyLevel::Manual,
        );

        $this->assertEquals(AutonomyLevel::Manual, $config->level);
        $this->assertDatabaseHas('autonomy_configs', [
            'scope' => 'stage',
            'stage' => 'implement',
            'level' => 'manual',
        ]);
    }

    public function test_validate_stage_can_equal_global(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Supervised,
        ]);

        $config = $this->resolver->validateAndSave(
            AutonomyScope::Stage,
            null,
            StageName::Implement,
            AutonomyLevel::Supervised,
        );

        $this->assertEquals(AutonomyLevel::Supervised, $config->level);
    }

    public function test_validate_issue_cannot_tighten_from_stage(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Assisted,
        ]);

        AutonomyConfig::create([
            'scope' => AutonomyScope::Stage,
            'scope_id' => null,
            'stage' => StageName::Implement,
            'level' => AutonomyLevel::Supervised,
        ]);

        $issue = Issue::factory()->create();

        $this->expectException(ValidationException::class);

        $this->resolver->validateAndSave(
            AutonomyScope::Issue,
            $issue->id,
            StageName::Implement,
            AutonomyLevel::Manual,
        );
    }

    public function test_validate_issue_can_loosen_from_stage(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Assisted,
        ]);

        AutonomyConfig::create([
            'scope' => AutonomyScope::Stage,
            'scope_id' => null,
            'stage' => StageName::Implement,
            'level' => AutonomyLevel::Supervised,
        ]);

        $issue = Issue::factory()->create();

        $config = $this->resolver->validateAndSave(
            AutonomyScope::Issue,
            $issue->id,
            StageName::Implement,
            AutonomyLevel::Assisted,
        );

        $this->assertEquals(AutonomyLevel::Assisted, $config->level);
    }

    public function test_validate_issue_can_equal_stage(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Supervised,
        ]);

        $issue = Issue::factory()->create();

        $config = $this->resolver->validateAndSave(
            AutonomyScope::Issue,
            $issue->id,
            StageName::Implement,
            AutonomyLevel::Supervised,
        );

        $this->assertEquals(AutonomyLevel::Supervised, $config->level);
    }

    public function test_validate_issue_all_stages_cannot_tighten_from_tightest_stage(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Autonomous,
        ]);

        AutonomyConfig::create([
            'scope' => AutonomyScope::Stage,
            'scope_id' => null,
            'stage' => StageName::Release,
            'level' => AutonomyLevel::Supervised,
        ]);

        $issue = Issue::factory()->create();

        $this->expectException(ValidationException::class);

        $this->resolver->validateAndSave(
            AutonomyScope::Issue,
            $issue->id,
            null,
            AutonomyLevel::Manual,
        );
    }

    public function test_validate_global_always_allowed(): void
    {
        $config = $this->resolver->validateAndSave(
            AutonomyScope::Global,
            null,
            null,
            AutonomyLevel::Autonomous,
        );

        $this->assertEquals(AutonomyLevel::Autonomous, $config->level);
    }

    public function test_validate_and_save_updates_existing(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Supervised,
        ]);

        $this->resolver->validateAndSave(
            AutonomyScope::Global,
            null,
            null,
            AutonomyLevel::Autonomous,
        );

        $this->assertDatabaseCount('autonomy_configs', 1);
        $this->assertDatabaseHas('autonomy_configs', [
            'scope' => 'global',
            'level' => 'autonomous',
        ]);
    }

    public function test_validation_error_message_is_clear(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Supervised,
        ]);

        try {
            $this->resolver->validateAndSave(
                AutonomyScope::Stage,
                null,
                StageName::Implement,
                AutonomyLevel::Autonomous,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $message = $e->errors()['level'][0];
            $this->assertStringContainsString('supervised', $message);
            $this->assertStringContainsString('autonomous', $message);
            $this->assertStringContainsString('tighten', $message);
        }
    }

    public function test_validation_error_message_for_issue_is_clear(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Assisted,
        ]);

        AutonomyConfig::create([
            'scope' => AutonomyScope::Stage,
            'scope_id' => null,
            'stage' => StageName::Implement,
            'level' => AutonomyLevel::Supervised,
        ]);

        $issue = Issue::factory()->create();

        try {
            $this->resolver->validateAndSave(
                AutonomyScope::Issue,
                $issue->id,
                StageName::Implement,
                AutonomyLevel::Manual,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $message = $e->errors()['level'][0];
            $this->assertStringContainsString('supervised', $message);
            $this->assertStringContainsString('manual', $message);
            $this->assertStringContainsString('loosen', $message);
        }
    }

    public function test_full_three_scope_cascade(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Autonomous,
        ]);

        AutonomyConfig::create([
            'scope' => AutonomyScope::Stage,
            'scope_id' => null,
            'stage' => StageName::Release,
            'level' => AutonomyLevel::Manual,
        ]);

        AutonomyConfig::create([
            'scope' => AutonomyScope::Stage,
            'scope_id' => null,
            'stage' => StageName::Implement,
            'level' => AutonomyLevel::Supervised,
        ]);

        $issue1 = Issue::factory()->create();
        $issue2 = Issue::factory()->create();

        AutonomyConfig::create([
            'scope' => AutonomyScope::Issue,
            'scope_id' => $issue1->id,
            'stage' => StageName::Release,
            'level' => AutonomyLevel::Assisted,
        ]);

        $this->assertEquals(AutonomyLevel::Autonomous, $this->resolver->resolve($issue1->id, StageName::Preflight));
        $this->assertEquals(AutonomyLevel::Supervised, $this->resolver->resolve($issue1->id, StageName::Implement));
        $this->assertEquals(AutonomyLevel::Assisted, $this->resolver->resolve($issue1->id, StageName::Release));
        $this->assertEquals(AutonomyLevel::Autonomous, $this->resolver->resolve($issue1->id, StageName::Verify));

        $this->assertEquals(AutonomyLevel::Manual, $this->resolver->resolve($issue2->id, StageName::Release));
        $this->assertEquals(AutonomyLevel::Supervised, $this->resolver->resolve($issue2->id, StageName::Implement));
        $this->assertEquals(AutonomyLevel::Autonomous, $this->resolver->resolve($issue2->id, StageName::Preflight));
    }

    public function test_resolve_with_no_config_at_all(): void
    {
        $issue = Issue::factory()->create();
        $result = $this->resolver->resolve($issue->id, StageName::Implement);

        $this->assertEquals(AutonomyLevel::Supervised, $result);
    }

    public function test_scopes_stored_as_autonomy_config_rows(): void
    {
        $this->resolver->validateAndSave(AutonomyScope::Global, null, null, AutonomyLevel::Assisted);
        $this->resolver->validateAndSave(AutonomyScope::Stage, null, StageName::Release, AutonomyLevel::Manual);

        $issue = Issue::factory()->create();
        $this->resolver->validateAndSave(AutonomyScope::Issue, $issue->id, StageName::Release, AutonomyLevel::Supervised);

        $this->assertDatabaseCount('autonomy_configs', 3);

        $global = AutonomyConfig::where('scope', 'global')->first();
        $this->assertEquals(AutonomyScope::Global, $global->scope);
        $this->assertNull($global->scope_id);
        $this->assertNull($global->stage);

        $stage = AutonomyConfig::where('scope', 'stage')->first();
        $this->assertEquals(AutonomyScope::Stage, $stage->scope);
        $this->assertEquals(StageName::Release, $stage->stage);

        $issueConfig = AutonomyConfig::where('scope', 'issue')->first();
        $this->assertEquals(AutonomyScope::Issue, $issueConfig->scope);
        $this->assertEquals($issue->id, $issueConfig->scope_id);
        $this->assertEquals(StageName::Release, $issueConfig->stage);
    }
}
