<?php

use App\Models\Component;
use App\Models\Repository;
use App\Models\Source;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component as LivewireComponent;

new
#[Title('Components')]
#[Layout('layouts::app')]
class extends LivewireComponent {
    public Source $source;

    public function mount(): void
    {
        if ($this->source->type->value !== 'jira') {
            abort(404);
        }
    }

    public function attachRepo(int $componentId, int $repositoryId): void
    {
        $component = $this->source->components()->findOrFail($componentId);
        $component->repositories()->syncWithoutDetaching([$repositoryId]);
    }

    public function detachRepo(int $componentId, int $repositoryId): void
    {
        $component = $this->source->components()->findOrFail($componentId);
        $component->repositories()->detach($repositoryId);
    }

    public function with(): array
    {
        return [
            'components' => $this->source->components()
                ->with('repositories')
                ->orderBy('name')
                ->get(),
            'allRepositories' => Repository::orderBy('name')->get(),
        ];
    }
};
?>

<div class="max-w-3xl mx-auto space-y-6">
    <div>
        <a href="{{ route('intake.index') }}" class="font-label text-[10px] text-primary uppercase tracking-widest hover:underline">
            ← Back to Intake
        </a>
        <h1 class="font-headline text-3xl font-bold text-on-surface mt-2">Components</h1>
        <p class="font-label text-[10px] text-outline uppercase tracking-widest mt-1">
            Jira · {{ $source->external_account }}
        </p>
        <p class="text-sm text-on-surface-variant mt-2">
            Map Jira components to the repositories where their issues should run. Components appear after the next sync.
        </p>
    </div>

    @if ($components->isEmpty())
        <div class="bg-surface-container-low rounded-xl p-8 text-center">
            <p class="text-on-surface-variant">No components discovered yet.</p>
            <p class="font-label text-[10px] text-outline uppercase tracking-widest mt-1">
                Run a sync to pull components from your selected projects.
            </p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($components as $component)
                @php $attachedIds = $component->repositories->pluck('id')->all(); @endphp
                <div class="bg-surface-container-low rounded-xl p-4" wire:key="component-{{ $component->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="text-sm font-semibold text-on-surface">{{ $component->name }}</h3>
                            <p class="font-label text-[10px] text-outline uppercase tracking-wider mt-0.5 font-mono">
                                id: {{ $component->external_id }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-3 pt-3 border-t border-outline-variant/20 space-y-2">
                        <span class="font-label text-[10px] text-outline uppercase tracking-wider">Repositories</span>

                        @if ($component->repositories->isEmpty())
                            <p class="font-label text-[10px] text-stage-stuck uppercase tracking-wider">
                                None attached · issues for this component can't start runs
                            </p>
                        @else
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($component->repositories as $repo)
                                    <span class="inline-flex items-center gap-1.5 rounded bg-secondary-container/30 text-secondary px-2 py-0.5 font-label text-[10px] tracking-wider font-mono">
                                        {{ $repo->name }}
                                        <button type="button"
                                                wire:click="detachRepo({{ $component->id }}, {{ $repo->id }})"
                                                aria-label="Detach {{ $repo->name }}"
                                                class="text-outline hover:text-error">×</button>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @php
                            $available = $allRepositories->reject(fn ($r) => in_array($r->id, $attachedIds, true));
                        @endphp
                        @if ($available->isNotEmpty())
                            <div class="flex items-center gap-2 flex-wrap pt-1">
                                <span class="font-label text-[10px] text-outline uppercase tracking-wider">Attach</span>
                                @foreach ($available as $repo)
                                    <button type="button"
                                            wire:click="attachRepo({{ $component->id }}, {{ $repo->id }})"
                                            class="inline-flex items-center rounded bg-surface-container-high text-on-surface-variant px-2 py-0.5 font-label text-[10px] tracking-wider font-mono hover:bg-primary hover:text-on-primary">
                                        + {{ $repo->name }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
