<?php

use App\Models\Source;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Edit Intake Rules')]
#[Layout('components.layouts.app')]
class extends Component {
    public Source $source;

    public string $includeLabels = '';

    public string $excludeLabels = '';

    public string $autoAcceptLabels = '';

    public bool $unassignedOnly = false;

    public function mount(): void
    {
        $rule = $this->source->filterRule;
        $this->includeLabels = implode(', ', $rule?->include_labels ?? []);
        $this->excludeLabels = implode(', ', $rule?->exclude_labels ?? []);
        $this->autoAcceptLabels = implode(', ', $rule?->auto_accept_labels ?? []);
        $this->unassignedOnly = (bool) ($rule?->unassigned_only ?? false);
    }

    public function save()
    {
        $include = $this->parseLabels($this->includeLabels);
        $exclude = $this->parseLabels($this->excludeLabels);

        $overlap = array_intersect($include, $exclude);
        if (! empty($overlap)) {
            $this->addError('includeLabels', 'Labels cannot appear in both include and exclude: '.implode(', ', $overlap));

            return;
        }

        $this->source->filterRule()->updateOrCreate(
            ['source_id' => $this->source->id],
            [
                'include_labels' => $include ?: null,
                'exclude_labels' => $exclude ?: null,
                'auto_accept_labels' => $this->parseLabels($this->autoAcceptLabels) ?: null,
                'unassigned_only' => $this->unassignedOnly,
            ],
        );

        session()->flash('success', "Intake rules saved for {$this->source->name}.");

        return $this->redirectRoute('intake.index', navigate: true);
    }

    private function parseLabels(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();
    }
};
?>

<div class="space-y-6 max-w-2xl">
    <div>
        <a href="{{ route('intake.index') }}" class="font-label text-[10px] text-primary uppercase tracking-widest hover:underline">
            ← Back to Intake
        </a>
        <h1 class="font-headline text-3xl font-bold text-on-surface mt-2">Intake Rules</h1>
        <p class="font-label text-[10px] text-outline uppercase tracking-widest mt-1">
            {{ $source->type->value }} · {{ $source->name }} · {{ $source->external_account }}
        </p>
    </div>

    <form wire:submit="save" class="space-y-5 bg-surface-container-low rounded-xl p-6">
        <div>
            <label for="include_labels" class="block font-label text-[10px] text-stage-verify uppercase tracking-widest mb-1">Include Labels</label>
            <input type="text" wire:model="includeLabels" id="include_labels"
                   placeholder="bug, performance, security"
                   class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface text-sm px-3 py-2 focus:border-primary focus:ring-primary">
            <p class="font-label text-[10px] text-outline uppercase tracking-widest mt-1">Comma-separated · Issue must have one of these</p>
            @error('includeLabels')
                <p class="text-xs text-error mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="exclude_labels" class="block font-label text-[10px] text-error uppercase tracking-widest mb-1">Exclude Labels</label>
            <input type="text" wire:model="excludeLabels" id="exclude_labels"
                   placeholder="wontfix, discussion, spike"
                   class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface text-sm px-3 py-2 focus:border-primary focus:ring-primary">
            <p class="font-label text-[10px] text-outline uppercase tracking-widest mt-1">Comma-separated · Issue skipped if it has any of these</p>
        </div>

        <div>
            <label for="auto_accept_labels" class="block font-label text-[10px] text-primary uppercase tracking-widest mb-1">Auto-Accept Labels</label>
            <input type="text" wire:model="autoAcceptLabels" id="auto_accept_labels"
                   placeholder="relay/auto"
                   class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface text-sm px-3 py-2 focus:border-primary focus:ring-primary">
            <p class="font-label text-[10px] text-outline uppercase tracking-widest mt-1">Comma-separated · Bypass queue and enter preflight directly</p>
        </div>

        <label class="flex items-start gap-3 pt-2 cursor-pointer">
            <input type="checkbox" wire:model="unassignedOnly"
                   class="mt-0.5 rounded border-outline-variant bg-surface-container-lowest text-primary focus:ring-primary">
            <span>
                <span class="text-sm text-on-surface">Unassigned only</span>
                <span class="block font-label text-[10px] text-outline uppercase tracking-widest mt-0.5">Never compete with ongoing human work</span>
            </span>
        </label>

        <div class="flex items-center gap-2 pt-3">
            <button type="submit" class="rounded-md bg-primary text-on-primary px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">
                Save Rules
            </button>
            <a href="{{ route('intake.index') }}" class="rounded-md bg-surface-container-high text-on-surface px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest">
                Cancel
            </a>
        </div>
    </form>
</div>
