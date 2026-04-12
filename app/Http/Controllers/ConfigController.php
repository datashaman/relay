<?php

namespace App\Http\Controllers;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\StageName;
use App\Models\AutonomyConfig;
use App\Models\EscalationRule;
use App\Services\AutonomyResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ConfigController extends Controller
{
    public function __construct(
        private AutonomyResolver $resolver,
    ) {}

    public function index()
    {
        $globalDefault = $this->resolver->getGlobalDefault();

        $stageOverrides = [];
        foreach (StageName::cases() as $stage) {
            $config = AutonomyConfig::where('scope', AutonomyScope::Stage)
                ->whereNull('scope_id')
                ->where('stage', $stage)
                ->first();
            $stageOverrides[$stage->value] = $config?->level;
        }

        $rules = EscalationRule::orderBy('order')->get();
        $iterationCap = config('relay.iteration_cap');

        return view('config.index', compact(
            'globalDefault',
            'stageOverrides',
            'rules',
            'iterationCap',
        ));
    }

    public function updateGlobal(Request $request)
    {
        $request->validate([
            'level' => ['required', 'string', 'in:' . implode(',', array_column(AutonomyLevel::cases(), 'value'))],
        ]);

        $level = AutonomyLevel::from($request->input('level'));

        $this->resolver->validateAndSave(AutonomyScope::Global, null, null, $level);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'level' => $level->value]);
        }

        return redirect()->route('config.index')->with('success', 'Global autonomy level updated.');
    }

    public function updateStage(Request $request, string $stage)
    {
        $stageName = StageName::from($stage);

        if ($request->input('level') === '' || $request->input('level') === null) {
            AutonomyConfig::where('scope', AutonomyScope::Stage)
                ->whereNull('scope_id')
                ->where('stage', $stageName)
                ->delete();

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => true, 'cleared' => true]);
            }

            return redirect()->route('config.index')->with('success', ucfirst($stage) . ' stage override removed.');
        }

        $request->validate([
            'level' => ['required', 'string', 'in:' . implode(',', array_column(AutonomyLevel::cases(), 'value'))],
        ]);

        $level = AutonomyLevel::from($request->input('level'));

        $this->resolver->validateAndSave(AutonomyScope::Stage, null, $stageName, $level);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'level' => $level->value]);
        }

        return redirect()->route('config.index')->with('success', ucfirst($stage) . ' stage override updated.');
    }

    public function updateIterationCap(Request $request)
    {
        $request->validate([
            'iteration_cap' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $cap = (int) $request->input('iteration_cap');
        config(['relay.iteration_cap' => $cap]);

        if (! app()->runningUnitTests()) {
            $this->setEnvValue('RELAY_ITERATION_CAP', (string) $cap);
        }

        return redirect()->route('config.index')->with('success', 'Iteration cap updated.');
    }

    public function preview(Request $request)
    {
        $results = [];
        foreach (StageName::cases() as $stage) {
            $globalDefault = $this->resolver->getGlobalDefault();
            $stageConfig = AutonomyConfig::where('scope', AutonomyScope::Stage)
                ->whereNull('scope_id')
                ->where('stage', $stage)
                ->first();
            $results[$stage->value] = $stageConfig?->level?->value ?? $globalDefault->value;
        }

        return response()->json($results);
    }

    private function setEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            file_put_contents($envPath, "{$key}={$value}\n");
            return;
        }

        $contents = file_get_contents($envPath);

        if (preg_match("/^{$key}=.*/m", $contents)) {
            $contents = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $contents);
        } else {
            $contents .= "\n{$key}={$value}";
        }

        file_put_contents($envPath, $contents);

        config(['relay.iteration_cap' => (int) $value]);
    }
}
