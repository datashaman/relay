<?php

use App\Models\Source;
use App\Services\OauthService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Select Jira Site')]
#[Layout('components.layouts.app')]
class extends Component {
    public function selectSite(string $cloudId, OauthService $oauth)
    {
        $pending = Cache::pull('jira_pending_site_selection');

        if (! $pending) {
            session()->flash('error', 'No pending Jira authorization. Please reconnect.');

            return $this->redirectRoute('intake.index', navigate: true);
        }

        $site = collect($pending['sites'])->firstWhere('id', $cloudId);

        if (! $site) {
            session()->flash('error', 'Invalid Jira site selection.');

            return $this->redirectRoute('intake.index', navigate: true);
        }

        $source = Source::firstOrCreate(
            ['type' => 'jira', 'external_account' => $site['name']],
            [
                'name' => 'Jira: '.$site['name'],
                'is_active' => true,
                'config' => ['cloud_id' => $cloudId, 'site_url' => $site['url'] ?? null],
            ],
        );

        if ($source->wasRecentlyCreated === false) {
            $source->update(['config' => ['cloud_id' => $cloudId, 'site_url' => $site['url'] ?? null]]);
        }

        $oauth->storeToken($source, 'jira', $pending['token_data']);

        session()->flash('success', 'Jira connected successfully ('.$site['name'].').');

        return $this->redirectRoute('intake.index', navigate: true);
    }

    public function with(): array
    {
        $pending = Cache::get('jira_pending_site_selection');

        return [
            'sites' => $pending['sites'] ?? [],
            'hasPending' => (bool) $pending,
        ];
    }
};
?>

<div class="max-w-lg mx-auto">
    <h1 class="text-2xl font-headline font-bold mb-6">Select a Jira Site</h1>

    @if (! $hasPending)
        <p class="text-sm text-error">No pending Jira authorization. Please reconnect.</p>
    @elseif (empty($sites))
        <p class="text-sm text-outline">No accessible Jira sites were returned.</p>
    @else
        <p class="text-sm text-on-surface-variant mb-6">Multiple Jira sites were found. Choose the one you want to connect.</p>
        <div class="space-y-2">
            @foreach ($sites as $site)
                <button type="button" wire:click="selectSite('{{ $site['id'] }}')"
                        wire:key="site-{{ $site['id'] }}"
                        class="w-full text-left px-4 py-3 bg-surface-container-low rounded-xl hover:bg-surface-container-high transition-colors">
                    <span class="font-medium text-on-surface">{{ $site['name'] }}</span>
                    @if (! empty($site['url']))
                        <span class="block text-sm text-on-surface-variant">{{ $site['url'] }}</span>
                    @endif
                </button>
            @endforeach
        </div>
    @endif
</div>
