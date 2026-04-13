<?php

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\StageName;
use App\Models\AutonomyConfig;
use App\Models\EscalationRule;
use App\Services\AutonomyResolver;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Autonomy Engine')]
#[Layout('components.layouts.app')]
class extends Component {
    public int $iterationCap = 5;

    public function mount(): void
    {
        $this->iterationCap = (int) config('relay.iteration_cap', 5);
    }

    public function setGlobal(string $level, AutonomyResolver $resolver): void
    {
        $level = AutonomyLevel::from($level);

        try {
            $resolver->validateAndSave(AutonomyScope::Global, null, null, $level);
        } catch (ValidationException $e) {
            $this->addError('global', $e->getMessage());

            return;
        }

        session()->flash('success', 'Global autonomy level updated.');
    }

    public function setStage(string $stage, string $level, AutonomyResolver $resolver): void
    {
        $stageName = StageName::from($stage);

        if ($level === '') {
            AutonomyConfig::where('scope', AutonomyScope::Stage)
                ->whereNull('scope_id')
                ->where('stage', $stageName)
                ->delete();

            return;
        }

        try {
            $resolver->validateAndSave(AutonomyScope::Stage, null, $stageName, AutonomyLevel::from($level));
        } catch (ValidationException $e) {
            $this->addError('stage.'.$stage, $e->getMessage());
        }
    }

    public function saveIterationCap(): void
    {
        $this->validate([
            'iterationCap' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        config(['relay.iteration_cap' => $this->iterationCap]);

        if (! app()->runningUnitTests()) {
            $this->writeEnvValue('RELAY_ITERATION_CAP', (string) $this->iterationCap);
        }

        session()->flash('success', 'Iteration cap updated.');
    }

    private function writeEnvValue(string $key, string $value): void
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
    }

    public function with(AutonomyResolver $resolver): array
    {
        $globalDefault = $resolver->getGlobalDefault();

        $stageOverrides = [];
        foreach (StageName::cases() as $stage) {
            $config = AutonomyConfig::where('scope', AutonomyScope::Stage)
                ->whereNull('scope_id')
                ->where('stage', $stage)
                ->first();
            $stageOverrides[$stage->value] = $config?->level;
        }

        return [
            'globalDefault' => $globalDefault,
            'stageOverrides' => $stageOverrides,
            'rules' => EscalationRule::orderBy('order')->get(),
        ];
    }
};
?>

@php
    $hierarchy = [
        ['num' => '01', 'key' => 'Escalation', 'desc' => 'Override rules triggered by sensitive files, labels, or patterns. Always tighten — cannot be bypassed.'],
        ['num' => '02', 'key' => 'Issue', 'desc' => 'Per-issue overrides. Can only loosen from the stage default.'],
        ['num' => '03', 'key' => 'Stage', 'desc' => 'Pipeline-specific constraints (Preflight, Implement, Verify, Release). Can only tighten from global.'],
        ['num' => '04', 'key' => 'Global', 'desc' => 'Default operational posture for all agent interactions.'],
    ];
    $shortLabel = [
        'manual' => 'Manual',
        'supervised' => 'Super',
        'assisted' => 'Assist',
        'autonomous' => 'Auto',
    ];
@endphp

<div>
<div class="space-y-6 mb-6">
    <div>
        <span class="font-label text-[10px] text-outline uppercase tracking-widest">System Configuration</span>
        <h1 class="font-headline text-3xl font-bold text-on-surface mt-1">Autonomy Engine</h1>
        <p class="text-sm text-on-surface-variant mt-2 max-w-2xl">
            Relay agents operate on a hierarchical constraint model. Higher-level rules take precedence,
            ensuring strict human-in-the-loop oversight when it matters most.
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        @foreach ($hierarchy as $layer)
            <div class="bg-surface-container-low rounded-xl p-4 flex gap-3">
                <span class="font-label text-[10px] text-outline uppercase tracking-widest pt-0.5 shrink-0">LVL {{ $layer['num'] }}</span>
                <div class="min-w-0">
                    <h3 class="font-label text-xs text-primary uppercase tracking-widest">{{ $layer['key'] }}</h3>
                    <p class="text-xs text-on-surface-variant mt-1 leading-snug">{{ $layer['desc'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="bg-surface-container-low rounded-xl p-4 border-l-4 border-secondary">
        <span class="font-label text-[10px] text-outline uppercase tracking-widest">Active Posture · Current Effective Level</span>
        <h2 class="font-headline text-3xl font-bold text-secondary mt-1">{{ ucfirst($globalDefault->value) }}</h2>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        {{-- Global Default --}}
        <div class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-headline font-medium mb-4">Global Default</h2>
            <p class="text-sm text-on-surface-variant mb-4">The baseline autonomy level applied to all stages unless overridden.</p>

            <div class="space-y-2">
                @foreach (\App\Enums\AutonomyLevel::cases() as $level)
                    <button type="button" wire:click="setGlobal('{{ $level->value }}')"
                            class="w-full text-left relative block p-3 rounded-md cursor-pointer transition-colors
                            {{ $globalDefault === $level
                                ? 'bg-surface-container-high border-l-2 border-secondary'
                                : 'border-l-2 border-transparent hover:bg-surface-container' }}">
                        <span class="font-label text-sm font-bold uppercase tracking-widest {{ $globalDefault === $level ? 'text-secondary' : 'text-on-surface' }}">{{ $level->value }}</span>
                        <p class="text-xs text-on-surface-variant mt-1">
                            @switch($level)
                                @case(\App\Enums\AutonomyLevel::Manual)
                                    Every action requires explicit approval. Full human control at each step.
                                    @break
                                @case(\App\Enums\AutonomyLevel::Supervised)
                                    Agents work but pause for approval at stage transitions. Recommended starting point.
                                    @break
                                @case(\App\Enums\AutonomyLevel::Assisted)
                                    Agents auto-advance through stages, pausing only on escalation rule matches.
                                    @break
                                @case(\App\Enums\AutonomyLevel::Autonomous)
                                    Fully autonomous. Agents run the entire pipeline without pausing.
                                    @break
                            @endswitch
                        </p>
                    </button>
                @endforeach
            </div>
            @error('global')
                <p class="mt-2 text-sm text-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Per-Stage Overrides --}}
        <div class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-headline font-medium mb-2">Per-Stage Overrides</h2>
            <p class="text-sm text-on-surface-variant mb-4">Stage overrides can only <strong>tighten</strong> from the global default ({{ ucfirst($globalDefault->value) }}). Select "Inherit" to use the global level.</p>

            <div class="space-y-3">
                @foreach (\App\Enums\StageName::cases() as $stage)
                    @php $currentOverride = $stageOverrides[$stage->value]; @endphp
                    <div class="space-y-1" wire:key="stage-{{ $stage->value }}">
                        <div class="font-label text-[10px] uppercase tracking-widest text-outline">{{ $stage->value }}</div>
                        <div class="flex rounded-lg overflow-hidden bg-surface-container-lowest divide-x divide-outline-variant/30">
                            @php
                                $pills = [['value' => '', 'label' => 'Inherit', 'rank' => -1, 'isInherit' => true]];
                                foreach (\App\Enums\AutonomyLevel::cases() as $lvl) {
                                    $pills[] = ['value' => $lvl->value, 'label' => $shortLabel[$lvl->value] ?? $lvl->value, 'rank' => $lvl->order(), 'isInherit' => false];
                                }
                            @endphp
                            @foreach ($pills as $pill)
                                @php
                                    $isActive = $pill['isInherit']
                                        ? $currentOverride === null
                                        : $currentOverride?->value === $pill['value'];
                                    $isHidden = ! $pill['isInherit'] && $pill['rank'] > $globalDefault->order();
                                @endphp
                                <button type="button"
                                        wire:click="setStage('{{ $stage->value }}', '{{ $pill['value'] }}')"
                                        @class([
                                            'flex-1 font-label text-[10px] uppercase tracking-wider px-1.5 py-2 transition-colors',
                                            'bg-secondary-container/30 text-secondary' => $isActive,
                                            'text-on-surface-variant hover:bg-surface-container' => ! $isActive,
                                            'hidden' => $isHidden,
                                        ])>
                                    {{ $pill['label'] }}
                                </button>
                            @endforeach
                        </div>
                        @error('stage.'.$stage->value)
                            <p class="text-xs text-error">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Escalation Rules (external CRUD via EscalationRuleController) --}}
        <div class="bg-surface-container-low rounded-xl p-6" x-data="{ modalOpen: false, editing: null }" wire:ignore.self>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-headline font-medium">Escalation Rules</h2>
                    <p class="text-sm text-on-surface-variant mt-1">Rules force tighter autonomy when conditions match before stage transitions.</p>
                </div>
                <button type="button" @click="modalOpen = true; editing = null" class="rounded-md bg-primary text-on-primary px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">Add Rule</button>
            </div>

            @if ($rules->isEmpty())
                <p class="text-sm text-outline">No escalation rules configured.</p>
            @else
                <div class="space-y-2">
                    @foreach ($rules as $rule)
                        @include('escalation-rules._card', ['rule' => $rule, 'order' => $loop->iteration])
                    @endforeach
                </div>
            @endif

            {{-- Rule Modal --}}
            <div x-show="modalOpen" x-cloak @keydown.escape.window="modalOpen = false" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4" aria-modal="true" role="dialog" @click.self="modalOpen = false">
                <div class="bg-surface-container-low w-full md:max-w-lg max-h-[85vh] overflow-y-auto rounded-2xl shadow-2xl shadow-black/60">
                    <div class="flex items-center justify-between px-5 py-4 sticky top-0 bg-surface-container-low">
                        <h2 class="font-headline text-xl font-bold text-on-surface">
                            <span x-text="editing ? 'Edit Escalation Rule' : 'Add Escalation Rule'"></span>
                        </h2>
                        <button type="button" @click="modalOpen = false" class="text-outline hover:text-on-surface text-2xl leading-none" aria-label="Close">×</button>
                    </div>
                    <form :action="editing ? `/escalation-rules/${editing.id}` : '{{ route('escalation-rules.store') }}'" method="POST" class="px-5 pb-5 space-y-4">
                        @csrf
                        <template x-if="editing">
                            <input type="hidden" name="_method" value="PUT">
                        </template>

                        <div>
                            <label class="block font-label text-[10px] text-on-surface-variant uppercase tracking-widest mb-1">Rule Name</label>
                            <input type="text" name="name" required :value="editing?.name || ''"
                                   class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary"
                                   placeholder="e.g., Security-sensitive paths">
                        </div>

                        <div x-data="{ type: editing?.conditionType || 'label_match' }">
                            <label class="block font-label text-[10px] text-on-surface-variant uppercase tracking-widest mb-1">Condition Type</label>
                            <select name="condition_type" required x-model="type"
                                    class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary">
                                <option value="label_match">Label Match</option>
                                <option value="file_path_match">File Path Match</option>
                                <option value="diff_size">Diff Size (threshold)</option>
                                <option value="touched_directory_match">Touched Directory Match</option>
                            </select>

                            <label class="block font-label text-[10px] text-on-surface-variant uppercase tracking-widest mt-4 mb-1">Condition Value</label>
                            <div class="flex gap-2">
                                <select name="condition_operator" x-show="type === 'diff_size'" x-cloak
                                        class="w-20 shrink-0 rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-2 py-2 text-sm font-label">
                                    <option value=">=">≥</option>
                                    <option value=">">&gt;</option>
                                    <option value="<=">≤</option>
                                    <option value="<">&lt;</option>
                                    <option value="=">=</option>
                                </select>
                                <input type="text" name="condition_value" required :value="editing?.conditionValue || ''"
                                       class="flex-1 rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm">
                            </div>
                        </div>

                        <div>
                            <label class="block font-label text-[10px] text-on-surface-variant uppercase tracking-widest mb-1">Target Autonomy Level</label>
                            <select name="target_level" required
                                    class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm">
                                @foreach (\App\Enums\AutonomyLevel::cases() as $level)
                                    <option value="{{ $level->value }}">{{ ucfirst($level->value) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex items-center gap-2 pt-2">
                            <button type="submit" class="rounded-md bg-primary text-on-primary px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">
                                <span x-text="editing ? 'Update Rule' : 'Create Rule'"></span>
                            </button>
                            <button type="button" @click="modalOpen = false" class="rounded-md bg-surface-container-high text-on-surface px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Iteration Cap --}}
        <div class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-headline font-medium mb-2">Iteration Cap</h2>
            <p class="text-sm text-on-surface-variant mb-4">Maximum Verify-to-Implement bounce cycles before an issue is marked stuck.</p>

            <div class="flex items-center gap-3 flex-wrap">
                <input type="number" wire:model="iterationCap" min="1" max="20"
                    class="iteration-cap-input w-20 rounded-md bg-surface-container-lowest border-outline-variant text-on-surface text-sm px-3 py-2 focus:border-primary focus:ring-primary">
                <button type="button" wire:click="saveIterationCap" class="rounded-md bg-primary text-on-primary px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">Save</button>
                <span class="font-label text-[10px] text-outline uppercase tracking-widest">Min 1 · Max 20</span>
            </div>
            <style>
                .iteration-cap-input::-webkit-outer-spin-button,
                .iteration-cap-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
                .iteration-cap-input { -moz-appearance: textfield; }
            </style>
            @error('iterationCap')
                <p class="mt-2 text-sm text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Preview Panel --}}
    <div class="lg:col-span-1">
        <div class="bg-surface-container-low rounded-xl p-6 sticky top-6">
            <h2 class="text-lg font-headline font-medium mb-2">Effective Autonomy Preview</h2>
            <p class="text-sm text-on-surface-variant mb-4">Shows the resolved autonomy level for a sample issue at each stage, before escalation rules.</p>

            <div class="space-y-3">
                @foreach (\App\Enums\StageName::cases() as $stage)
                    @php
                        $effective = $stageOverrides[$stage->value]?->value ?? $globalDefault->value;
                        $isOverridden = $stageOverrides[$stage->value] !== null;
                    @endphp
                    <div class="flex items-center justify-between p-3 rounded-md bg-surface-container">
                        <div>
                            <div class="text-sm font-medium">{{ ucfirst($stage->value) }}</div>
                            <div class="text-xs {{ $isOverridden ? 'text-primary' : 'text-outline' }}">
                                {{ $isOverridden ? 'stage override' : 'from global' }}
                            </div>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-md font-label text-[10px] uppercase tracking-widest
                            @if ($effective === 'manual') bg-error-container/30 text-error
                            @elseif ($effective === 'supervised') bg-stage-stuck/20 text-stage-stuck
                            @elseif ($effective === 'assisted') bg-primary-container/30 text-primary
                            @else bg-secondary-container/30 text-secondary
                            @endif">
                            {{ ucfirst($effective) }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
</div>
