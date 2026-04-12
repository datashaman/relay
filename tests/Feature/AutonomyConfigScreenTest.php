<?php

namespace Tests\Feature;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\StageName;
use App\Models\AutonomyConfig;
use App\Models\EscalationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutonomyConfigScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_displays_autonomy_config_screen(): void
    {
        $response = $this->get('/autonomy');

        $response->assertStatus(200);
        $response->assertSee('Configure Autonomy');
        $response->assertSee('Global Default');
        $response->assertSee('Per-Stage Overrides');
        $response->assertSee('Escalation Rules');
        $response->assertSee('Iteration Cap');
        $response->assertSee('Effective Autonomy Preview');
    }

    public function test_index_shows_global_default_as_supervised(): void
    {
        $response = $this->get('/autonomy');

        $response->assertStatus(200);
        $response->assertSee('Supervised');
    }

    public function test_index_shows_all_four_level_descriptions(): void
    {
        $response = $this->get('/autonomy');

        $response->assertSee('Every action requires explicit approval');
        $response->assertSee('Agents work but pause for approval at stage transitions');
        $response->assertSee('Agents auto-advance through stages');
        $response->assertSee('Fully autonomous');
    }

    public function test_update_global_changes_default_level(): void
    {
        $response = $this->post('/autonomy/global', ['level' => 'autonomous']);

        $response->assertRedirect('/autonomy');

        $config = AutonomyConfig::where('scope', AutonomyScope::Global)
            ->whereNull('scope_id')
            ->whereNull('stage')
            ->first();

        $this->assertEquals(AutonomyLevel::Autonomous, $config->level);
    }

    public function test_update_global_rejects_invalid_level(): void
    {
        $response = $this->post('/autonomy/global', ['level' => 'invalid']);

        $response->assertSessionHasErrors('level');
    }

    public function test_update_stage_creates_override(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Supervised,
        ]);

        $response = $this->post('/autonomy/stage/preflight', ['level' => 'manual']);

        $response->assertRedirect('/autonomy');

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

        $response = $this->post('/autonomy/stage/preflight', ['level' => '']);

        $response->assertRedirect('/autonomy');

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

        $response = $this->post('/autonomy/stage/preflight', ['level' => 'autonomous']);

        $response->assertSessionHasErrors('level');
    }

    public function test_update_iteration_cap_valid(): void
    {
        $response = $this->post('/autonomy/iteration-cap', ['iteration_cap' => 10]);

        $response->assertRedirect('/autonomy');
        $response->assertSessionHas('success');

        config(['relay.iteration_cap' => 5]);
    }

    public function test_update_iteration_cap_rejects_below_min(): void
    {
        $response = $this->post('/autonomy/iteration-cap', ['iteration_cap' => 0]);

        $response->assertSessionHasErrors('iteration_cap');
    }

    public function test_update_iteration_cap_rejects_above_max(): void
    {
        $response = $this->post('/autonomy/iteration-cap', ['iteration_cap' => 21]);

        $response->assertSessionHasErrors('iteration_cap');
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

        $response = $this->get('/autonomy');

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

        $response = $this->get('/autonomy');

        $response->assertSee('overridden');
    }

    public function test_preview_returns_effective_levels(): void
    {
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => null,
            'level' => AutonomyLevel::Supervised,
        ]);

        AutonomyConfig::create([
            'scope' => AutonomyScope::Stage,
            'scope_id' => null,
            'stage' => StageName::Release,
            'level' => AutonomyLevel::Manual,
        ]);

        $response = $this->getJson('/autonomy/preview');

        $response->assertStatus(200);
        $response->assertJson([
            'preflight' => 'supervised',
            'implement' => 'supervised',
            'verify' => 'supervised',
            'release' => 'manual',
        ]);
    }

    public function test_preview_panel_shows_all_stages(): void
    {
        $response = $this->get('/autonomy');

        $response->assertSee('Preflight');
        $response->assertSee('Implement');
        $response->assertSee('Verify');
        $response->assertSee('Release');
        $response->assertSee('from global');
    }

    public function test_nav_link_present(): void
    {
        $response = $this->get('/autonomy');

        $response->assertSee('href="/autonomy"', false);
    }
}
