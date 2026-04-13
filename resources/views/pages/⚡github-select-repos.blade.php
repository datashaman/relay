<?php

use App\Models\Source;
use App\Services\GitHubClient;
use App\Services\OauthService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Select GitHub Repositories')]
#[Layout('layouts::app')]
class extends Component {
    public Source $source;

    public array $selected = [];

    public ?string $error = null;

    public function mount(): void
    {
        if ($this->source->type->value !== 'github') {
            abort(404);
        }

        $this->selected = $this->source->config['repositories'] ?? [];
    }

    public function save()
    {
        $this->source->update([
            'config' => array_merge($this->source->config ?? [], [
                'repositories' => array_values($this->selected),
            ]),
        ]);

        session()->flash('success', count($this->selected).' repositories selected for '.$this->source->external_account.'.');

        return $this->redirectRoute('intake.index', navigate: true);
    }

    public function toggle(string $repo): void
    {
        if (in_array($repo, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$repo]));
        } else {
            $this->selected[] = $repo;
        }
    }

    public function with(OauthService $oauth): array
    {
        $token = $this->source->oauthTokens()->where('provider', 'github')->first();

        if (! $token) {
            return ['repos' => [], 'error' => 'No GitHub token found for this source. Reconnect to refresh.'];
        }

        try {
            $token = $oauth->refreshIfExpired($token);
            $client = new GitHubClient($token, $oauth);
            $repos = collect($client->allRepos())
                ->sortByDesc('updated_at')
                ->map(fn ($r) => [
                    'full_name' => $r['full_name'],
                    'private' => (bool) ($r['private'] ?? false),
                    'description' => $r['description'] ?? null,
                ])
                ->values()
                ->all();

            return ['repos' => $repos, 'error' => null];
        } catch (\Throwable $e) {
            return ['repos' => [], 'error' => 'Failed to load repositories: '.$e->getMessage()];
        }
    }
};
?>

<div class="max-w-3xl mx-auto space-y-6">
    <div>
        <a href="{{ route('intake.index') }}" class="font-label text-[10px] text-primary uppercase tracking-widest hover:underline">
            ← Back to Intake
        </a>
        <h1 class="font-headline text-3xl font-bold text-on-surface mt-2">Select Repositories</h1>
        <p class="font-label text-[10px] text-outline uppercase tracking-widest mt-1">
            GitHub · {{ $source->external_account }}
        </p>
        <p class="text-sm text-on-surface-variant mt-2">
            Relay will only sync issues from the repositories you select here. Toggle to change at any time.
        </p>
    </div>

    @if ($error)
        <div class="rounded-md bg-error-container/20 border-l-4 border-error px-4 py-3">
            <p class="text-sm text-error">{{ $error }}</p>
        </div>
    @elseif (empty($repos))
        <p class="text-sm text-outline">No repositories accessible with this token.</p>
    @else
        <div class="flex items-center justify-between bg-surface-container-low rounded-xl p-4">
            <span class="font-label text-[10px] text-outline uppercase tracking-widest">
                {{ count($selected) }} / {{ count($repos) }} selected
            </span>
            <button type="button" wire:click="save"
                    class="rounded-md bg-primary text-on-primary px-4 py-2 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">
                Save Selection
            </button>
        </div>

        <div class="bg-surface-container-low rounded-xl divide-y divide-outline-variant/20 overflow-hidden">
            @foreach ($repos as $repo)
                @php $isSelected = in_array($repo['full_name'], $selected, true); @endphp
                <button type="button" wire:click="toggle('{{ $repo['full_name'] }}')"
                        wire:key="repo-{{ $repo['full_name'] }}"
                        class="flex items-start gap-3 w-full px-4 py-3 text-left hover:bg-surface-container transition-colors {{ $isSelected ? 'bg-secondary-container/20' : '' }}">
                    <span class="mt-0.5 flex-shrink-0 w-4 h-4 rounded border-2 flex items-center justify-center {{ $isSelected ? 'bg-secondary border-secondary' : 'border-outline-variant' }}">
                        @if ($isSelected)
                            <svg class="w-3 h-3 text-on-secondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        @endif
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-on-surface">{{ $repo['full_name'] }}</span>
                            @if ($repo['private'])
                                <span class="inline-flex items-center rounded bg-surface-container-high text-on-surface-variant px-1.5 py-0.5 font-label text-[9px] uppercase tracking-wider">Private</span>
                            @endif
                        </div>
                        @if ($repo['description'])
                            <p class="text-xs text-on-surface-variant mt-0.5 line-clamp-1">{{ $repo['description'] }}</p>
                        @endif
                    </div>
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
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
