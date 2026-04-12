<?php

namespace Tests\Feature;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\StageName;
use App\Models\AutonomyConfig;
use App\Models\EscalationRule;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Services\EscalationRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscalationRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    private EscalationRuleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EscalationRuleService::class);
    }

    // --- Condition matching ---

    public function test_label_match_condition(): void
    {
        $issue = Issue::factory()->create(['labels' => ['bug', 'security']]);
        $rule = EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'target_level' => AutonomyLevel::Manual,
        ]);

        $matched = $this->service->evaluateRules($issue);
        $this->assertCount(1, $matched);
        $this->assertEquals($rule->id, $matched[0]->id);
    }

    public function test_label_match_is_case_insensitive(): void
    {
        $issue = Issue::factory()->create(['labels' => ['Security']]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
        ]);

        $matched = $this->service->evaluateRules($issue);
        $this->assertCount(1, $matched);
    }

    public function test_label_match_no_match(): void
    {
        $issue = Issue::factory()->create(['labels' => ['bug', 'enhancement']]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
        ]);

        $matched = $this->service->evaluateRules($issue);
        $this->assertEmpty($matched);
    }

    public function test_file_path_match_condition(): void
    {
        $issue = Issue::factory()->create();
        EscalationRule::factory()->create([
            'condition' => ['type' => 'file_path_match', 'value' => 'src/config/*'],
            'target_level' => AutonomyLevel::Supervised,
        ]);

        $matched = $this->service->evaluateRules($issue, ['files' => ['src/config/database.php', 'app/Models/User.php']]);
        $this->assertCount(1, $matched);
    }

    public function test_file_path_no_match(): void
    {
        $issue = Issue::factory()->create();
        EscalationRule::factory()->create([
            'condition' => ['type' => 'file_path_match', 'value' => 'src/config/*'],
        ]);

        $matched = $this->service->evaluateRules($issue, ['files' => ['app/Models/User.php']]);
        $this->assertEmpty($matched);
    }

    public function test_file_path_no_context(): void
    {
        $issue = Issue::factory()->create();
        EscalationRule::factory()->create([
            'condition' => ['type' => 'file_path_match', 'value' => 'src/config/*'],
        ]);

        $matched = $this->service->evaluateRules($issue);
        $this->assertEmpty($matched);
    }

    public function test_diff_size_condition_above_threshold(): void
    {
        $issue = Issue::factory()->create();
        EscalationRule::factory()->create([
            'condition' => ['type' => 'diff_size', 'value' => '500'],
            'target_level' => AutonomyLevel::Manual,
        ]);

        $matched = $this->service->evaluateRules($issue, ['diff_size' => 600]);
        $this->assertCount(1, $matched);
    }

    public function test_diff_size_condition_below_threshold(): void
    {
        $issue = Issue::factory()->create();
        EscalationRule::factory()->create([
            'condition' => ['type' => 'diff_size', 'value' => '500'],
        ]);

        $matched = $this->service->evaluateRules($issue, ['diff_size' => 100]);
        $this->assertEmpty($matched);
    }

    public function test_diff_size_exact_threshold_matches(): void
    {
        $issue = Issue::factory()->create();
        EscalationRule::factory()->create([
            'condition' => ['type' => 'diff_size', 'value' => '500'],
        ]);

        $matched = $this->service->evaluateRules($issue, ['diff_size' => 500]);
        $this->assertCount(1, $matched);
    }

    public function test_diff_size_no_context(): void
    {
        $issue = Issue::factory()->create();
        EscalationRule::factory()->create([
            'condition' => ['type' => 'diff_size', 'value' => '500'],
        ]);

        $matched = $this->service->evaluateRules($issue);
        $this->assertEmpty($matched);
    }

    public function test_touched_directory_match_condition(): void
    {
        $issue = Issue::factory()->create();
        EscalationRule::factory()->create([
            'condition' => ['type' => 'touched_directory_match', 'value' => 'database/'],
            'target_level' => AutonomyLevel::Supervised,
        ]);

        $matched = $this->service->evaluateRules($issue, ['directories' => ['database/', 'app/']]);
        $this->assertCount(1, $matched);
    }

    public function test_touched_directory_match_subdirectory(): void
    {
        $issue = Issue::factory()->create();
        EscalationRule::factory()->create([
            'condition' => ['type' => 'touched_directory_match', 'value' => 'database'],
        ]);

        $matched = $this->service->evaluateRules($issue, ['directories' => ['database/migrations']]);
        $this->assertCount(1, $matched);
    }

    public function test_touched_directory_no_match(): void
    {
        $issue = Issue::factory()->create();
        EscalationRule::factory()->create([
            'condition' => ['type' => 'touched_directory_match', 'value' => 'database'],
        ]);

        $matched = $this->service->evaluateRules($issue, ['directories' => ['app/', 'config/']]);
        $this->assertEmpty($matched);
    }

    public function test_unknown_condition_type_does_not_match(): void
    {
        $issue = Issue::factory()->create();
        EscalationRule::factory()->create([
            'condition' => ['type' => 'unknown_type', 'value' => 'anything'],
        ]);

        $matched = $this->service->evaluateRules($issue);
        $this->assertEmpty($matched);
    }

    // --- Disabled rules ---

    public function test_disabled_rules_are_skipped(): void
    {
        $issue = Issue::factory()->create(['labels' => ['security']]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'is_enabled' => false,
        ]);

        $matched = $this->service->evaluateRules($issue);
        $this->assertEmpty($matched);
    }

    // --- Multiple rules ---

    public function test_multiple_rules_can_match(): void
    {
        $issue = Issue::factory()->create(['labels' => ['security', 'critical']]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'order' => 0,
        ]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'critical'],
            'order' => 1,
        ]);

        $matched = $this->service->evaluateRules($issue);
        $this->assertCount(2, $matched);
    }

    public function test_tightest_target_wins_across_matched_rules(): void
    {
        $issue = Issue::factory()->create(['labels' => ['security', 'critical']]);
        AutonomyConfig::factory()->create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Autonomous,
        ]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'target_level' => AutonomyLevel::Supervised,
            'order' => 0,
        ]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'critical'],
            'target_level' => AutonomyLevel::Manual,
            'order' => 1,
        ]);

        $level = $this->service->resolveWithEscalation($issue, StageName::Implement);
        $this->assertEquals(AutonomyLevel::Manual, $level);
    }

    // --- resolveWithEscalation ---

    public function test_resolve_returns_base_level_when_no_rules_match(): void
    {
        $issue = Issue::factory()->create(['labels' => ['enhancement']]);
        AutonomyConfig::factory()->create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Assisted,
        ]);

        $level = $this->service->resolveWithEscalation($issue, StageName::Implement);
        $this->assertEquals(AutonomyLevel::Assisted, $level);
    }

    public function test_resolve_tightens_when_rule_matches(): void
    {
        $issue = Issue::factory()->create(['labels' => ['security']]);
        AutonomyConfig::factory()->create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Autonomous,
        ]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'target_level' => AutonomyLevel::Supervised,
        ]);

        $level = $this->service->resolveWithEscalation($issue, StageName::Implement);
        $this->assertEquals(AutonomyLevel::Supervised, $level);
    }

    public function test_resolve_does_not_loosen_when_base_is_tighter(): void
    {
        $issue = Issue::factory()->create(['labels' => ['security']]);
        AutonomyConfig::factory()->create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Manual,
        ]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'target_level' => AutonomyLevel::Supervised,
        ]);

        $level = $this->service->resolveWithEscalation($issue, StageName::Implement);
        $this->assertEquals(AutonomyLevel::Manual, $level);
    }

    // --- Stage event recording ---

    public function test_matched_rule_recorded_on_stage_event(): void
    {
        $issue = Issue::factory()->create(['labels' => ['security']]);
        $run = Run::factory()->create(['issue_id' => $issue->id]);
        $stage = Stage::factory()->create(['run_id' => $run->id, 'name' => StageName::Implement]);

        $rule = EscalationRule::factory()->create([
            'name' => 'Security escalation',
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'target_level' => AutonomyLevel::Manual,
        ]);

        $this->service->resolveWithEscalation($issue, StageName::Implement, [], $stage);

        $event = StageEvent::where('stage_id', $stage->id)->where('type', 'escalation_matched')->first();
        $this->assertNotNull($event);
        $this->assertEquals('system', $event->actor);
        $this->assertCount(1, $event->payload['matched_rules']);
        $this->assertEquals($rule->id, $event->payload['matched_rules'][0]['id']);
        $this->assertEquals('Security escalation', $event->payload['matched_rules'][0]['name']);
        $this->assertEquals('manual', $event->payload['forced_level']);
    }

    public function test_no_event_when_no_stage_provided(): void
    {
        $issue = Issue::factory()->create(['labels' => ['security']]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'target_level' => AutonomyLevel::Manual,
        ]);

        $this->service->resolveWithEscalation($issue, StageName::Implement);

        $this->assertEquals(0, StageEvent::count());
    }

    public function test_no_event_when_no_rules_match(): void
    {
        $issue = Issue::factory()->create(['labels' => ['enhancement']]);
        $run = Run::factory()->create(['issue_id' => $issue->id]);
        $stage = Stage::factory()->create(['run_id' => $run->id]);

        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
        ]);

        $this->service->resolveWithEscalation($issue, StageName::Implement, [], $stage);

        $this->assertEquals(0, StageEvent::count());
    }

    public function test_all_matched_rules_recorded_in_event(): void
    {
        $issue = Issue::factory()->create(['labels' => ['security', 'critical']]);
        $run = Run::factory()->create(['issue_id' => $issue->id]);
        $stage = Stage::factory()->create(['run_id' => $run->id, 'name' => StageName::Implement]);

        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'target_level' => AutonomyLevel::Supervised,
            'order' => 0,
        ]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'critical'],
            'target_level' => AutonomyLevel::Manual,
            'order' => 1,
        ]);

        $this->service->resolveWithEscalation($issue, StageName::Implement, [], $stage);

        $event = StageEvent::where('stage_id', $stage->id)->first();
        $this->assertCount(2, $event->payload['matched_rules']);
        $this->assertEquals('manual', $event->payload['forced_level']);
    }

    // --- Context-based evaluation ---

    public function test_resolve_with_file_path_context(): void
    {
        $issue = Issue::factory()->create();
        AutonomyConfig::factory()->create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Autonomous,
        ]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'file_path_match', 'value' => '*.env*'],
            'target_level' => AutonomyLevel::Manual,
        ]);

        $level = $this->service->resolveWithEscalation(
            $issue,
            StageName::Implement,
            ['files' => ['.env.production', 'app/Http/Controller.php']],
        );
        $this->assertEquals(AutonomyLevel::Manual, $level);
    }

    public function test_resolve_with_diff_size_context(): void
    {
        $issue = Issue::factory()->create();
        AutonomyConfig::factory()->create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Autonomous,
        ]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'diff_size', 'value' => '1000'],
            'target_level' => AutonomyLevel::Supervised,
        ]);

        $level = $this->service->resolveWithEscalation(
            $issue,
            StageName::Implement,
            ['diff_size' => 1500],
        );
        $this->assertEquals(AutonomyLevel::Supervised, $level);
    }

    // --- Rule ordering ---

    public function test_rules_evaluated_in_order(): void
    {
        $issue = Issue::factory()->create(['labels' => ['security']]);

        $rule1 = EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'order' => 5,
        ]);
        $rule2 = EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'order' => 1,
        ]);

        $matched = $this->service->evaluateRules($issue);
        $this->assertEquals($rule2->id, $matched[0]->id);
        $this->assertEquals($rule1->id, $matched[1]->id);
    }

    // --- Issue with null labels ---

    public function test_label_match_with_null_labels(): void
    {
        $issue = Issue::factory()->create(['labels' => null]);
        EscalationRule::factory()->create([
            'condition' => ['type' => 'label_match', 'value' => 'security'],
        ]);

        $matched = $this->service->evaluateRules($issue);
        $this->assertEmpty($matched);
    }

    // --- CRUD UI tests ---

    public function test_index_shows_rules(): void
    {
        $rule = EscalationRule::factory()->create([
            'name' => 'Security check',
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'target_level' => AutonomyLevel::Manual,
            'order' => 0,
        ]);

        $response = $this->get(route('escalation-rules.index'));
        $response->assertOk();
        $response->assertSee('Security check');
        $response->assertSee('label match');
        $response->assertSee('security');
        $response->assertSee('manual');
    }

    public function test_index_shows_empty_state(): void
    {
        $response = $this->get(route('escalation-rules.index'));
        $response->assertOk();
        $response->assertSee('No escalation rules configured');
    }

    public function test_create_form_renders(): void
    {
        $response = $this->get(route('escalation-rules.create'));
        $response->assertOk();
        $response->assertSee('Add Escalation Rule');
    }

    public function test_store_creates_rule(): void
    {
        $response = $this->post(route('escalation-rules.store'), [
            'name' => 'New rule',
            'condition_type' => 'label_match',
            'condition_value' => 'security',
            'target_level' => 'manual',
        ]);

        $response->assertRedirect(route('escalation-rules.index'));
        $this->assertDatabaseHas('escalation_rules', [
            'name' => 'New rule',
            'target_level' => 'manual',
        ]);

        $rule = EscalationRule::first();
        $this->assertEquals(['type' => 'label_match', 'value' => 'security'], $rule->condition);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->post(route('escalation-rules.store'), []);
        $response->assertSessionHasErrors(['name', 'condition_type', 'condition_value', 'target_level']);
    }

    public function test_store_validates_condition_type(): void
    {
        $response = $this->post(route('escalation-rules.store'), [
            'name' => 'Test',
            'condition_type' => 'invalid_type',
            'condition_value' => 'val',
            'target_level' => 'manual',
        ]);
        $response->assertSessionHasErrors('condition_type');
    }

    public function test_store_validates_target_level(): void
    {
        $response = $this->post(route('escalation-rules.store'), [
            'name' => 'Test',
            'condition_type' => 'label_match',
            'condition_value' => 'val',
            'target_level' => 'invalid_level',
        ]);
        $response->assertSessionHasErrors('target_level');
    }

    public function test_edit_form_renders_with_existing_data(): void
    {
        $rule = EscalationRule::factory()->create([
            'name' => 'Existing rule',
            'condition' => ['type' => 'diff_size', 'value' => '500'],
            'target_level' => AutonomyLevel::Supervised,
        ]);

        $response = $this->get(route('escalation-rules.edit', $rule));
        $response->assertOk();
        $response->assertSee('Edit Escalation Rule');
        $response->assertSee('Existing rule');
        $response->assertSee('500');
    }

    public function test_update_modifies_rule(): void
    {
        $rule = EscalationRule::factory()->create([
            'name' => 'Old name',
            'condition' => ['type' => 'label_match', 'value' => 'bug'],
            'target_level' => AutonomyLevel::Supervised,
        ]);

        $response = $this->put(route('escalation-rules.update', $rule), [
            'name' => 'New name',
            'condition_type' => 'diff_size',
            'condition_value' => '1000',
            'target_level' => 'manual',
        ]);

        $response->assertRedirect(route('escalation-rules.index'));
        $rule->refresh();
        $this->assertEquals('New name', $rule->name);
        $this->assertEquals(['type' => 'diff_size', 'value' => '1000'], $rule->condition);
        $this->assertEquals(AutonomyLevel::Manual, $rule->target_level);
    }

    public function test_destroy_deletes_rule(): void
    {
        $rule = EscalationRule::factory()->create();

        $response = $this->delete(route('escalation-rules.destroy', $rule));
        $response->assertRedirect(route('escalation-rules.index'));
        $this->assertDatabaseMissing('escalation_rules', ['id' => $rule->id]);
    }

    public function test_toggle_enables_and_disables(): void
    {
        $rule = EscalationRule::factory()->create(['is_enabled' => true, 'name' => 'Toggle test']);

        $this->post(route('escalation-rules.toggle', $rule));
        $rule->refresh();
        $this->assertFalse($rule->is_enabled);

        $this->post(route('escalation-rules.toggle', $rule));
        $rule->refresh();
        $this->assertTrue($rule->is_enabled);
    }

    public function test_move_up_swaps_order(): void
    {
        $rule1 = EscalationRule::factory()->create(['order' => 0]);
        $rule2 = EscalationRule::factory()->create(['order' => 1]);

        $this->post(route('escalation-rules.move-up', $rule2));

        $rule1->refresh();
        $rule2->refresh();
        $this->assertEquals(1, $rule1->order);
        $this->assertEquals(0, $rule2->order);
    }

    public function test_move_down_swaps_order(): void
    {
        $rule1 = EscalationRule::factory()->create(['order' => 0]);
        $rule2 = EscalationRule::factory()->create(['order' => 1]);

        $this->post(route('escalation-rules.move-down', $rule1));

        $rule1->refresh();
        $rule2->refresh();
        $this->assertEquals(1, $rule1->order);
        $this->assertEquals(0, $rule2->order);
    }

    public function test_reorder_bulk_updates(): void
    {
        $rule1 = EscalationRule::factory()->create(['order' => 0]);
        $rule2 = EscalationRule::factory()->create(['order' => 1]);
        $rule3 = EscalationRule::factory()->create(['order' => 2]);

        $this->post(route('escalation-rules.reorder'), [
            'ids' => [$rule3->id, $rule1->id, $rule2->id],
        ]);

        $rule1->refresh();
        $rule2->refresh();
        $rule3->refresh();
        $this->assertEquals(1, $rule1->order);
        $this->assertEquals(2, $rule2->order);
        $this->assertEquals(0, $rule3->order);
    }

    public function test_store_auto_assigns_order(): void
    {
        EscalationRule::factory()->create(['order' => 5]);

        $this->post(route('escalation-rules.store'), [
            'name' => 'New rule',
            'condition_type' => 'label_match',
            'condition_value' => 'test',
            'target_level' => 'manual',
        ]);

        $newRule = EscalationRule::where('name', 'New rule')->first();
        $this->assertEquals(6, $newRule->order);
    }

    public function test_nav_link_to_escalation_rules(): void
    {
        $response = $this->get(route('escalation-rules.index'));
        $response->assertSee('Escalation');
    }

    public function test_disabled_rules_shown_with_reduced_opacity(): void
    {
        EscalationRule::factory()->create(['is_enabled' => false, 'name' => 'Disabled rule']);

        $response = $this->get(route('escalation-rules.index'));
        $response->assertSee('Disabled rule');
        $response->assertSee('Disabled');
    }

    public function test_delete_confirmation_gate(): void
    {
        EscalationRule::factory()->create();

        $response = $this->get(route('escalation-rules.index'));
        $response->assertSee("confirm('Delete this rule?')", false);
    }
}
