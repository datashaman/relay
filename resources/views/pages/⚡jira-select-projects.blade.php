<?php

use App\Models\Source;
use App\Services\JiraClient;
use App\Services\OauthService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Title('Select Jira Projects')]
#[Layout('layouts::app')]
class extends Component {
    public Source $source;

    public array $selected = [];

    public array $selectedStatuses = [];

    public bool $onlyMine = false;

    public bool $onlyActiveSprint = false;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        if ($this->source->type->value !== 'jira') {
            abort(404);
        }

        $this->selected = $this->source->config['projects'] ?? [];
        $this->selectedStatuses = $this->source->config['statuses'] ?? [];
        $this->onlyMine = (bool) ($this->source->config['only_mine'] ?? false);
        $this->onlyActiveSprint = (bool) ($this->source->config['only_active_sprint'] ?? false);
    }

    public function save()
    {
        $this->source->update([
            'config' => array_merge($this->source->config ?? [], [
                'projects' => array_values($this->selected),
                'statuses' => array_values($this->selectedStatuses),
                'only_mine' => $this->onlyMine,
                'only_active_sprint' => $this->onlyActiveSprint,
            ]),
        ]);

        session()->flash('success', 'Jira filters saved for '.$this->source->external_account.'.');

        return $this->redirectRoute('intake.index', navigate: true);
    }

    public function toggle(string $key): void
    {
        if (in_array($key, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$key]));
        } else {
            $this->selected[] = $key;
        }
    }

    public function toggleStatus(string $name): void
    {
        if (in_array($name, $this->selectedStatuses, true)) {
            $this->selectedStatuses = array_values(array_diff($this->selectedStatuses, [$name]));
        } else {
            $this->selectedStatuses[] = $name;
        }
    }

    public function with(OauthService $oauth): array
    {
        $token = $this->source->oauthTokens()->where('provider', 'jira')->first();

        if (! $token) {
            return [
                'projects' => [],
                'statuses' => [],
                'error' => 'No Jira token found for this source. Reconnect to refresh.',
            ];
        }

        try {
            $token = $oauth->refreshIfExpired($token);
            $client = new JiraClient($token, $oauth, $this->source);

            $projects = collect($client->listProjects())
                ->map(fn ($p) => [
                    'key' => $p['key'],
                    'name' => $p['name'],
                    'project_type' => $p['projectTypeKey'] ?? null,
                ]);

            if ($this->search !== '') {
                $needle = strtolower($this->search);
                $projects = $projects->filter(fn ($p) => str_contains(strtolower($p['key']), $needle)
                    || str_contains(strtolower($p['name']), $needle));
            }

            $statuses = collect($client->listStatuses())
                ->pluck('name')
                ->unique()
                ->sort()
                ->values()
                ->all();

            return [
                'projects' => $projects->sortBy('key')->values()->all(),
                'statuses' => $statuses,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'projects' => [],
                'statuses' => [],
                'error' => 'Failed to load projects: '.$e->getMessage(),
            ];
        }
    }
};
?>

<div class="max-w-3xl mx-auto space-y-6">
    <div>
        <a href="{{ route('intake.index') }}" class="font-label text-[10px] text-primary uppercase tracking-widest hover:underline">
            ← Back to Intake
        </a>
        <h1 class="font-headline text-3xl font-bold text-on-surface mt-2">Select Projects</h1>
        <p class="font-label text-[10px] text-outline uppercase tracking-widest mt-1">
            Jira · {{ $source->external_account }}
        </p>
        <p class="text-sm text-on-surface-variant mt-2">
            Relay will only sync issues from the projects you select here. Leave empty to sync from all accessible projects.
        </p>
    </div>

    <div class="bg-surface-container-low rounded-xl p-4 space-y-3">
        <span class="font-label text-[10px] text-outline uppercase tracking-widest">Additional filters</span>
        <label class="flex items-center gap-3 cursor-pointer" for="only-mine">
            <input type="checkbox" id="only-mine" wire:model.live="onlyMine"
                   class="w-4 h-4 rounded border-outline-variant bg-surface-container-lowest text-secondary focus:ring-secondary">
            <span class="text-sm text-on-surface">My issues only <span class="text-on-surface-variant">(assignee = currentUser())</span></span>
        </label>
        <label class="flex items-center gap-3 cursor-pointer" for="only-active-sprint">
            <input type="checkbox" id="only-active-sprint" wire:model.live="onlyActiveSprint"
                   class="w-4 h-4 rounded border-outline-variant bg-surface-container-lowest text-secondary focus:ring-secondary">
            <span class="text-sm text-on-surface">Active sprint only <span class="text-on-surface-variant">(sprint in openSprints())</span></span>
        </label>
    </div>

    @if (! empty($statuses))
        <div class="bg-surface-container-low rounded-xl p-4 space-y-3">
            <div class="flex items-center justify-between">
                <span class="font-label text-[10px] text-outline uppercase tracking-widest">Lanes (status)</span>
                <span class="font-label text-[10px] text-outline uppercase tracking-widest">
                    {{ count($selectedStatuses) }} selected · empty = all
                </span>
            </div>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($statuses as $statusName)
                    @php $isSelected = in_array($statusName, $selectedStatuses, true); @endphp
                    <button type="button" wire:click="toggleStatus('{{ $statusName }}')"
                            wire:key="status-{{ $statusName }}"
                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                            class="inline-flex items-center rounded px-2 py-1 font-label text-[10px] uppercase tracking-wider transition-colors {{ $isSelected ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest' }}">
                        {{ $statusName }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    <div class="bg-surface-container-low rounded-xl p-4 flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-center gap-3 flex-1 min-w-48">
            <label class="font-label text-[10px] text-outline uppercase tracking-widest shrink-0" for="project-search">Filter</label>
            <div class="relative flex-1">
                <input type="text" id="project-search" wire:model.live.debounce.200ms="search"
                       placeholder="key or name…"
                       class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface text-sm px-3 py-2 pr-8 focus:border-primary focus:ring-primary">
                <span wire:loading wire:target="search" class="absolute right-2 top-1/2 -translate-y-1/2 font-label text-[10px] text-outline uppercase">…</span>
            </div>
        </div>
        <span class="font-label text-[10px] text-outline uppercase tracking-widest">
            {{ count($selected) }} selected
        </span>
        <button type="button" wire:click="save"
                class="rounded-md bg-primary text-on-primary px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">
            Save Selection
        </button>
    </div>

    @if ($error)
        <div class="rounded-md bg-error-container/20 border-l-4 border-error px-4 py-3">
            <p class="text-sm text-error">{{ $error }}</p>
        </div>
    @elseif (empty($projects))
        <p class="text-sm text-outline">
            @if ($search !== '')
                No projects matched "{{ $search }}".
            @else
                No projects accessible with this token.
            @endif
        </p>
    @else
        <div class="bg-surface-container-low rounded-xl divide-y divide-outline-variant/20 overflow-hidden">
            @foreach ($projects as $project)
                @php $isSelected = in_array($project['key'], $selected, true); @endphp
                <div wire:key="project-{{ $project['key'] }}"
                     class="flex items-start gap-3 px-4 py-3 transition-colors {{ $isSelected ? 'bg-secondary-container/20' : 'hover:bg-surface-container' }}">
                    <button type="button" wire:click="toggle('{{ $project['key'] }}')"
                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                            aria-label="{{ $isSelected ? 'Deselect' : 'Select' }} {{ $project['key'] }}"
                            class="mt-0.5 flex-shrink-0 w-4 h-4 rounded border-2 flex items-center justify-center {{ $isSelected ? 'bg-secondary border-secondary' : 'border-outline-variant hover:border-primary' }}">
                        @if ($isSelected)
                            <svg class="w-3 h-3 text-on-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        @endif
                    </button>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-on-surface">{{ $project['key'] }}</span>
                            <span class="text-sm text-on-surface-variant">{{ $project['name'] }}</span>
                            @if ($project['project_type'])
                                <span class="inline-flex items-center rounded bg-surface-container-high text-on-surface-variant px-1.5 py-0.5 font-label text-[9px] uppercase tracking-wider">{{ $project['project_type'] }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex items-center justify-end gap-2">
            <button type="button" wire:click="save"
                    class="rounded-md bg-primary text-on-primary px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">
                Save Selection
            </button>
            <a href="{{ route('intake.index') }}" class="rounded-md bg-surface-container-high text-on-surface px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest">
                Cancel
            </a>
        </div>
    @endif
</div>
