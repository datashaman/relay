<?php

namespace Tests\Feature;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\StageName;
use App\Models\AutonomyConfig;
use App\Models\EscalationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConfigScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_displays_config_screen(): void
    {
        $response = $this->get('/config');

        $response->assertStatus(200);
        $response->assertSee('Autonomy Engine');
        $response->assertSee('Global Default');
        $response->assertSee('Per-Stage Overrides');
        $response->assertSee('Escalation Rules');
        $response->assertSee('Iteration Cap');
        $response->assertSee('Effective Autonomy Preview');
    }

    public function test_index_shows_global_default_as_supervised(): void
    {
        $response = $this->get('/config');

        $response->assertStatus(200);
        $response->assertSee('Supervised');
    }

    public function test_index_shows_all_four_level_descriptions(): void
    {
        $response = $this->get('/config');

        $response->assertSee('Every action requires explicit approval');
        $response->assertSee('Agents work but pause for approval at stage transitions');
        $response->assertSee('Agents auto-advance through stages');
        $response->assertSee('Fully autonomous');
    }

    public function test_update_global_changes_default_level(): void
    {
        Livewire::test('pages::config')
            ->call('setGlobal', 'autonomous')
            ->assertHasNoErrors();

        $config = AutonomyConfig::where('scope', AutonomyScope::Global)
            ->whereNull('scope_id')
            ->whereNull('stage')
            ->first();

        $this->assertEquals(AutonomyLevel::Autonomous, $config->level);
    }

    public function test_update_global_rejects_invalid_level(): void
    {
        $this->expectException(\ValueError::class);

        Livewire::test('pages::config')
            ->call('setGlobal', 'invalid');
    }

    public function test_update_stage_creates_override(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Supervised,
        ]);

        Livewire::test('pages::config')
            ->call('setStage', 'preflight', 'manual')
            ->assertHasNoErrors();

        $config = AutonomyConfig::where('scope', AutonomyScope::Stage)
            ->where('stage', StageName::Preflight)
            ->first();

        $this->assertEquals(AutonomyLevel::Manual, $config->level);
    }

    public function test_update_stage_removes_override_when_inherit(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Stage,
            'scope_id' => null,
            'stage' => StageName::Preflight,
            'level' => AutonomyLevel::Manual,
        ]);

        Livewire::test('pages::config')
            ->call('setStage', 'preflight', '');

        $this->assertNull(
            AutonomyConfig::where('scope', AutonomyScope::Stage)
                ->where('stage', StageName::Preflight)
                ->first()
        );
    }

    public function test_update_stage_rejects_looser_than_global(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Supervised,
        ]);

        Livewire::test('pages::config')
            ->call('setStage', 'preflight', 'autonomous')
            ->assertHasErrors('stage.preflight');
    }

    public function test_update_iteration_cap_valid(): void
    {
        Livewire::test('pages::config')
            ->set('iterationCap', 10)
            ->call('saveIterationCap')
            ->assertHasNoErrors();

        config(['relay.iteration_cap' => 5]);
    }

    public function test_update_iteration_cap_rejects_below_min(): void
    {
        Livewire::test('pages::config')
            ->set('iterationCap', 0)
            ->call('saveIterationCap')
            ->assertHasErrors('iterationCap');
    }

    public function test_update_iteration_cap_rejects_above_max(): void
    {
        Livewire::test('pages::config')
            ->set('iterationCap', 21)
            ->call('saveIterationCap')
            ->assertHasErrors('iterationCap');
    }

    public function test_index_shows_escalation_rules(): void
    {
        EscalationRule::create([
            'name' => 'Security files',
            'condition' => ['type' => 'label_match', 'value' => 'security'],
            'target_level' => AutonomyLevel::Manual,
            'scope' => 'global',
            'order' => 1,
            'is_enabled' => true,
        ]);

        $response = $this->get('/config');

        $response->assertSee('Security files');
        $response->assertSee('label match');
    }

    public function test_index_shows_stage_overrides(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Stage,
            'scope_id' => null,
            'stage' => StageName::Release,
            'level' => AutonomyLevel::Manual,
        ]);

        $response = $this->get('/config');

        $response->assertSee('stage override');
    }

    public function test_preview_panel_shows_all_stages(): void
    {
        $response = $this->get('/config');

        $response->assertSee('Preflight');
        $response->assertSee('Implement');
        $response->assertSee('Verify');
        $response->assertSee('Release');
        $response->assertSee('from global');
    }

    public function test_nav_link_present(): void
    {
        $response = $this->get('/config');

        $response->assertSee('href="/config"', false);
    }
}
