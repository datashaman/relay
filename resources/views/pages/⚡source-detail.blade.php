<?php

use App\Enums\FrameworkSource;
use App\Enums\IssueStatus;
use App\Jobs\SyncSourceIssuesJob;
use App\Models\Issue;
use App\Models\OauthToken;
use App\Models\Repository;
use App\Models\Source;
use App\Services\FrameworkDetector;
use App\Services\GitHubClient;
use App\Services\JiraClient;
use App\Services\OauthService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Source Detail')]
#[Layout('layouts::app')]
class extends Component
{
    public Source $source;

    /** Flash message for the last test-connection call */
    public ?array $testResult = null;

    /** Flash message for the last sync-now call */
    public ?array $syncResult = null;

    /** "repoFullName" → currently-editing flag */
    public array $editingFramework = [];

    public function mount(): void
    {
        $this->source->ensureWebhookSecret();
    }

    #[On('echo-private:intake,.SourceSynced')]
    public function handleSourceSynced(): void
    {
        // Re-render handled automatically by Livewire when this method is called.
    }

    #[On('echo-private:intake,.IntakeQueueChanged')]
    public function handleIntakeQueueChanged(): void
    {
        // Re-render handled automatically by Livewire when this method is called.
    }

    public function syncNow(): void
    {
        SyncSourceIssuesJob::dispatch($this->source);
        $this->syncResult = ['ok' => true, 'label' => 'Queued ✓'];
    }

    public function testConnection(OauthService $oauth): void
    {
        $token = OauthToken::query()
            ->where('source_id', $this->source->id)
            ->where('provider', $this->source->type->value)
            ->first();

        if (! $token) {
            $this->testResult = ['ok' => false, 'label' => 'No token ✗'];

            return;
        }

        try {
            $token = $oauth->refreshIfExpired($token);

            if ($this->source->type->value === 'github') {
                (new GitHubClient($token, $oauth))->listRepos(page: 1, perPage: 1);
            } elseif ($this->source->type->value === 'jira') {
                (new JiraClient($token, $oauth, $this->source))->listProjects();
            }

            $this->testResult = ['ok' => true, 'label' => 'OK ✓'];
        } catch (Throwable $e) {
            $this->testResult = ['ok' => false, 'label' => 'Failed ✗'];
        }
    }

    public function togglePause(): void
    {
        $this->source->update(['is_intake_paused' => ! $this->source->is_intake_paused]);
        $this->source->refresh();
    }

    public function togglePauseRepo(string $repoFullName): void
    {
        if ($this->source->type->value !== 'github') {
            return;
        }

        $repos = $this->source->config['repositories'] ?? [];
        if (! in_array($repoFullName, $repos, true)) {
            return;
        }

        $paused = $this->source->paused_repositories ?? [];

        if (in_array($repoFullName, $paused, true)) {
            $paused = array_values(array_diff($paused, [$repoFullName]));
        } else {
            $paused[] = $repoFullName;
        }

        $this->source->update(['paused_repositories' => $paused]);
        $this->source->refresh();
    }

    public function startEditFramework(string $repoFullName): void
    {
        $this->editingFramework[$repoFullName] = true;
    }

    public function cancelEditFramework(string $repoFullName): void
    {
        unset($this->editingFramework[$repoFullName]);
    }

    public function saveFramework(string $repoFullName, string $framework): void
    {
        if ($this->source->type->value !== 'github') {
            return;
        }

        $repos = $this->source->config['repositories'] ?? [];
        if (! in_array($repoFullName, $repos, true)) {
            return;
        }

        if (! in_array($framework, FrameworkDetector::ALLOWED, true)) {
            session()->flash('error', 'Unknown framework slug.');

            return;
        }

        $repository = Repository::firstOrCreate(['name' => $repoFullName]);
        $repository->forceFill([
            'framework' => $framework,
            'framework_source' => FrameworkSource::Manual,
        ])->save();

        unset($this->editingFramework[$repoFullName]);
    }

    /**
     * Compute webhook state string for this source.
     * green = is_active && sync_error === null && (webhook managed || no webhook expected)
     * amber = sync error or partial webhook failure (needs_permission / error)
     * red   = disconnected or terminal failure
     */
    private function webhookStateFor(): string
    {
        $source = $this->source;
        $selectedRepos = $source->config['repositories'] ?? [];
        $managedWebhookStates = $source->config['managed_webhooks'] ?? [];
        $repoStates = collect($selectedRepos)->mapWithKeys(
            fn ($repo) => [$repo => $managedWebhookStates[$repo]['state'] ?? null]
        );
        $needsPermissionRepos = $repoStates->filter(fn ($state) => $state === 'needs_permission')->keys()->values();
        $errorRepos = $repoStates->filter(fn ($state) => $state === 'error')->keys()->values();
        $managedRepos = $repoStates->filter(fn ($state) => $state === 'managed')->keys()->values();

        $jiraProjects = $source->config['projects'] ?? [];
        $jiraWebhookMeta = $source->config['managed_jira_webhook'] ?? null;

        if ($source->type->value === 'github') {
            if (empty($selectedRepos)) {
                return 'unconfigured';
            }

            if ($needsPermissionRepos->isNotEmpty()) {
                return 'needs_permission';
            }

            if ($errorRepos->isNotEmpty()) {
                return 'error';
            }

            if ($managedRepos->count() === count($selectedRepos)) {
                return 'managed';
            }

            return 'manual';
        }

        // Jira
        if (empty($jiraProjects)) {
            return 'unconfigured';
        }

        return match ($jiraWebhookMeta['state'] ?? null) {
            'managed' => 'managed',
            'needs_permission' => 'needs_permission',
            'error' => 'error',
            default => 'manual',
        };
    }

    public function with(): array
    {
        $this->source->refresh();

        $repos = $this->source->type->value === 'github'
            ? ($this->source->config['repositories'] ?? [])
            : [];

        $repositoriesByName = Repository::whereIn('name', $repos)->get()->keyBy('name');

        $queuedCount = Issue::active()
            ->where('status', IssueStatus::Queued)
            ->where('source_id', $this->source->id)
            ->count();

        $webhookState = $this->webhookStateFor();

        $selectedRepos = $this->source->config['repositories'] ?? [];
        $managedWebhookStates = $this->source->config['managed_webhooks'] ?? [];
        $repoStates = collect($selectedRepos)->mapWithKeys(
            fn ($repo) => [$repo => $managedWebhookStates[$repo]['state'] ?? null]
        );
        $needsPermissionRepos = $repoStates->filter(fn ($state) => $state === 'needs_permission')->keys()->values();
        $manualRepos = $repoStates->filter(fn ($state) => $state === 'manual')->keys()->values();
        $errorRepos = $repoStates->filter(fn ($state) => $state === 'error')->keys()->values();
        $managedRepos = $repoStates->filter(fn ($state) => $state === 'managed')->keys()->values();

        $jiraWebhookMeta = $this->source->config['managed_jira_webhook'] ?? null;

        $webhookUrl = $this->source->type->value === 'github'
            ? route('webhooks.github', $this->source)
            : route('webhooks.jira', [$this->source, $this->source->webhook_secret]);

        $jiraManualUrl = $this->source->type->value === 'jira'
            ? route('webhooks.jira', [$this->source, $this->source->webhook_secret])
            : null;

        $rule = $this->source->filterRule;

        $componentCount = $this->source->type->value === 'jira'
            ? $this->source->components()->count()
            : 0;

        $componentsWithRepos = $this->source->type->value === 'jira'
            ? $this->source->components()->has('repositories')->count()
            : 0;

        return [
            'repos' => $repos,
            'pausedRepos' => $this->source->paused_repositories ?? [],
            'repositoriesByName' => $repositoriesByName,
            'frameworkOptions' => FrameworkDetector::ALLOWED,
            'queuedCount' => $queuedCount,
            'webhookState' => $webhookState,
            'webhookUrl' => $webhookUrl,
            'jiraManualUrl' => $jiraManualUrl,
            'selectedRepos' => $selectedRepos,
            'managedWebhookStates' => $managedWebhookStates,
            'needsPermissionRepos' => $needsPermissionRepos,
            'manualRepos' => $manualRepos,
            'errorRepos' => $errorRepos,
            'managedRepos' => $managedRepos,
            'jiraWebhookMeta' => $jiraWebhookMeta,
            'rule' => $rule,
            'componentCount' => $componentCount,
            'componentsWithRepos' => $componentsWithRepos,
            'jiraProjects' => $this->source->config['projects'] ?? [],
            'onlyMine' => ! empty($this->source->config['only_mine']),
            'onlyActiveSprint' => ! empty($this->source->config['only_active_sprint']),
            'jiraStatuses' => $this->source->config['statuses'] ?? [],
        ];
    }
};
?>

<div class="space-y-6">
    {{-- Header --}}
    <div>
        <a href="{{ route('intake.index') }}" class="font-label text-[10px] text-primary uppercase tracking-widest hover:underline">
            ← Back to Intake
        </a>
        <div class="flex items-start justify-between gap-3 mt-2">
            <div>
                <div class="flex items-center gap-1.5 flex-wrap mb-1">
                    @php
                        $typePillClass = $source->type->value === 'github'
                            ? 'bg-surface-container-high text-on-surface'
                            : 'bg-primary-container/30 text-primary';
                    @endphp
                    <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 {{ $typePillClass }} font-label text-[10px] uppercase tracking-wider">
                        <x-source-icon :type="$source->type->value" class="w-3 h-3" />
                        {{ $source->type->value }}
                    </span>
                    @if ($source->is_active)
                        <span class="inline-flex items-center rounded bg-secondary-container/30 text-secondary px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">
                            Connected
                        </span>
                    @else
                        <span class="inline-flex items-center rounded bg-surface-container-high text-outline px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">
                            Disconnected
                        </span>
                    @endif
                    @if ($source->is_intake_paused)
                        <span class="inline-flex items-center rounded bg-stage-stuck/20 text-stage-stuck px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">
                            Paused
                        </span>
                    @endif
                    @if ($source->sync_error)
                        <span class="inline-flex items-center rounded bg-error-container/30 text-error px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">
                            Sync Error
                        </span>
                    @endif
                </div>
                <h1 class="font-headline text-3xl font-bold text-on-surface">{{ $source->external_account }}</h1>
            </div>
            @if ($source->is_active)
                <button type="button" wire:click="togglePause"
                        class="shrink-0 rounded-md px-3 py-1.5 font-label text-[10px] uppercase tracking-wider {{ $source->is_intake_paused ? 'bg-primary text-on-primary hover:bg-primary/90' : 'bg-surface-container-high text-on-surface hover:bg-surface-container-highest' }}">
                    {{ $source->is_intake_paused ? 'Resume' : 'Pause' }}
                </button>
            @endif
        </div>
    </div>

    {{-- Connection section --}}
    <section class="bg-surface-container-low rounded-xl p-4 space-y-3">
        <h2 class="font-label text-[10px] text-outline uppercase tracking-widest">Connection</h2>
        <div class="flex items-center gap-2 flex-wrap">
            @if ($source->last_synced_at)
                <p class="font-label text-[10px] text-outline uppercase tracking-widest">
                    Synced {{ $source->last_synced_at->diffForHumans(null, true) }} ago
                </p>
            @else
                <p class="font-label text-[10px] text-outline uppercase tracking-widest">
                    Never synced
                </p>
            @endif
            @if ($source->sync_interval)
                <span class="font-label text-[10px] text-outline uppercase tracking-widest">·</span>
                <p class="font-label text-[10px] text-outline uppercase tracking-widest">
                    Interval {{ $source->sync_interval }}m
                </p>
            @endif
        </div>
        @if ($source->sync_error)
            <div class="rounded-md bg-error-container/20 border-l-2 border-error px-2 py-1.5">
                <p class="text-xs text-error leading-snug">{{ $source->sync_error }}</p>
                @if ($source->next_retry_at)
                    <p class="font-label text-[10px] text-outline uppercase tracking-wider mt-1">
                        Retry {{ $source->next_retry_at->diffForHumans() }}
                    </p>
                @endif
            </div>
        @endif
        <div class="flex items-center gap-3 pt-1">
            @if ($source->is_active)
                <button type="button" wire:click="syncNow"
                        class="font-label text-[10px] uppercase tracking-widest leading-none {{ $syncResult ? ($syncResult['ok'] ? 'text-secondary' : 'text-error') : 'text-secondary' }} hover:underline">
                    <span wire:loading.remove wire:target="syncNow">{{ $syncResult['label'] ?? 'Sync Now' }}</span>
                    <span wire:loading wire:target="syncNow">Syncing…</span>
                </button>
                <button type="button" wire:click="testConnection"
                        class="font-label text-[10px] uppercase tracking-widest leading-none {{ $testResult ? ($testResult['ok'] ? 'text-secondary' : 'text-error') : 'text-primary' }} hover:underline">
                    <span wire:loading.remove wire:target="testConnection">{{ $testResult['label'] ?? 'Test' }}</span>
                    <span wire:loading wire:target="testConnection">Testing…</span>
                </button>
            @endif
        </div>
    </section>

    {{-- Scope section --}}
    <section class="bg-surface-container-low rounded-xl p-4 space-y-3">
        <h2 class="font-label text-[10px] text-outline uppercase tracking-widest">Scope</h2>

        {{-- Repositories (GitHub only) --}}
        @if ($source->type->value === 'github')
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <span class="font-label text-[10px] text-outline uppercase tracking-wider">Repositories</span>
                    <a href="{{ route('github.select-repos', $source) }}" class="font-label text-[10px] text-primary uppercase tracking-wider hover:underline">
                        {{ empty($repos) ? 'Choose' : 'Edit' }} →
                    </a>
                </div>
                @if (empty($repos))
                    <p class="font-label text-[10px] text-stage-stuck uppercase tracking-wider">
                        None selected · sync will fail until you pick repos
                    </p>
                @else
                    <ul class="divide-y divide-outline-variant/20">
                        @foreach ($repos as $repoName)
                            @php
                                $repoPaused = in_array($repoName, $pausedRepos, true);
                                $repoModel = $repositoriesByName[$repoName] ?? null;
                                $isEditingFramework = ! empty($editingFramework[$repoName]);
                            @endphp
                            <li wire:key="repo-{{ $repoName }}" class="flex flex-col gap-1 py-1.5">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <span class="font-mono text-[11px] text-on-surface-variant truncate">{{ $repoName }}</span>
                                        @if ($repoPaused)
                                            <span class="inline-flex items-center rounded bg-stage-stuck/20 text-stage-stuck px-1.5 py-0.5 font-label text-[9px] uppercase tracking-wider shrink-0">
                                                Paused
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded bg-secondary-container/30 text-secondary px-1.5 py-0.5 font-label text-[9px] uppercase tracking-wider shrink-0">
                                                Active
                                            </span>
                                        @endif
                                    </div>
                                    <button type="button"
                                            wire:click="togglePauseRepo('{{ $repoName }}')"
                                            class="shrink-0 rounded px-2 py-0.5 font-label text-[10px] uppercase tracking-wider {{ $repoPaused ? 'bg-primary text-on-primary hover:bg-primary/90' : 'bg-surface-container-high text-on-surface hover:bg-surface-container-highest' }}">
                                        {{ $repoPaused ? 'Resume' : 'Pause' }}
                                    </button>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    @if ($isEditingFramework)
                                        <span class="font-label text-[10px] text-outline uppercase tracking-wider">Stack</span>
                                        <select wire:change="saveFramework('{{ $repoName }}', $event.target.value)"
                                                class="bg-surface-container-high text-on-surface rounded px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider"
                                                data-testid="framework-select-{{ $source->id }}-{{ $repoName }}">
                                            <option value="">— choose —</option>
                                            @foreach ($frameworkOptions as $option)
                                                <option value="{{ $option }}" @if ($repoModel?->framework === $option) selected @endif>{{ $option }}</option>
                                            @endforeach
                                        </select>
                                        <button type="button"
                                                wire:click="cancelEditFramework('{{ $repoName }}')"
                                                class="font-label text-[10px] text-outline uppercase tracking-wider hover:underline">Cancel</button>
                                    @else
                                        <span class="inline-flex items-center rounded bg-surface-container-high text-on-surface-variant px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider shrink-0">
                                            Stack: {{ $repoModel?->framework ?? '—' }}
                                        </span>
                                        @if ($repoModel?->framework_source)
                                            <span class="font-label text-[9px] text-outline uppercase tracking-wider">
                                                ({{ $repoModel->framework_source->value }})
                                            </span>
                                        @endif
                                        <button type="button"
                                                wire:click="startEditFramework('{{ $repoName }}')"
                                                class="font-label text-[10px] text-primary uppercase tracking-wider hover:underline"
                                                aria-label="Edit stack for {{ $repoName }}">
                                            Edit
                                        </button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        {{-- Projects + filters (Jira only) --}}
        @if ($source->type->value === 'jira')
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <span class="font-label text-[10px] text-outline uppercase tracking-wider">Projects &amp; filters</span>
                    <a href="{{ route('jira.select-projects', $source) }}" class="font-label text-[10px] text-primary uppercase tracking-wider hover:underline">
                        {{ empty($jiraProjects) && ! $onlyMine && ! $onlyActiveSprint ? 'Choose' : 'Edit' }} →
                    </a>
                </div>
                @if (empty($jiraProjects))
                    <p class="font-label text-[10px] text-stage-stuck uppercase tracking-wider">
                        No projects selected · sync will pull from all accessible projects
                    </p>
                @else
                    <div class="flex items-center gap-1.5 flex-wrap">
                        @foreach ($jiraProjects as $projectKey)
                            <span class="inline-flex items-center rounded bg-surface-container-high text-on-surface-variant px-1.5 py-0.5 font-label text-[10px] tracking-wider font-mono">
                                {{ $projectKey }}
                            </span>
                        @endforeach
                    </div>
                @endif
                @if ($onlyMine || $onlyActiveSprint)
                    <div class="flex items-center gap-1.5 flex-wrap">
                        @if ($onlyMine)
                            <span class="inline-flex items-center rounded bg-secondary-container text-on-secondary-container px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">My issues</span>
                        @endif
                        @if ($onlyActiveSprint)
                            <span class="inline-flex items-center rounded bg-secondary-container text-on-secondary-container px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">Active sprint</span>
                        @endif
                    </div>
                @endif
                @if (! empty($jiraStatuses))
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <span class="font-label text-[10px] text-outline uppercase tracking-wider">Lanes:</span>
                        @foreach ($jiraStatuses as $statusName)
                            <span class="inline-flex items-center rounded bg-secondary-container text-on-secondary-container px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">{{ $statusName }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </section>

    {{-- Webhook section --}}
    <section class="bg-surface-container-low rounded-xl p-4 space-y-1.5">
        <h2 class="font-label text-[10px] text-outline uppercase tracking-widest mb-2">Webhook</h2>
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                @php
                    $stateStyles = [
                        'managed' => 'bg-secondary-container text-on-secondary-container',
                        'needs_permission' => 'bg-error-container text-on-error-container',
                        'error' => 'bg-error-container text-on-error-container',
                        'manual' => 'bg-surface-container-high text-on-surface-variant',
                        'unconfigured' => 'bg-surface-container-high text-on-surface-variant',
                    ][$webhookState] ?? 'bg-surface-container-high text-on-surface-variant';

                    $stateLabels = [
                        'managed' => 'Managed',
                        'needs_permission' => 'Needs Permission',
                        'error' => 'Error',
                        'manual' => 'Manual Fallback',
                        'unconfigured' => 'Not Configured',
                    ];
                @endphp
                <span class="inline-flex items-center rounded px-1.5 py-0.5 font-label text-[9px] uppercase tracking-wider {{ $stateStyles }}">
                    {{ $stateLabels[$webhookState] ?? 'Manual' }}
                </span>
            </div>
            @if ($source->webhook_last_delivery_at)
                <span class="font-label text-[10px] text-outline uppercase tracking-wider">
                    Last delivery {{ $source->webhook_last_delivery_at->diffForHumans(null, true) }} ago
                </span>
            @else
                <span class="font-label text-[10px] text-outline uppercase tracking-wider">Never delivered</span>
            @endif
        </div>

        @if ($source->type->value === 'github')
            @if ($webhookState === 'managed')
                <p class="text-xs text-secondary leading-snug">
                    Relay is managing webhook setup for {{ $managedRepos->count() }} {{ \Illuminate\Support\Str::plural('repository', $managedRepos->count()) }}.
                </p>
            @elseif ($webhookState === 'needs_permission')
                <p class="text-xs text-error leading-snug">
                    Relay could not manage {{ $needsPermissionRepos->count() }} {{ \Illuminate\Support\Str::plural('repository', $needsPermissionRepos->count()) }} due to missing GitHub webhook permissions. Reconnect GitHub with webhook admin scope.
                </p>
            @elseif ($webhookState === 'error')
                <p class="text-xs text-error leading-snug">
                    Relay could not finish webhook setup for one or more repositories. Check repository access and retry sync.
                </p>
            @elseif ($webhookState === 'unconfigured')
                <p class="text-xs text-on-surface-variant leading-snug">
                    Pick repositories first and Relay will attempt webhook provisioning automatically.
                </p>
            @else
                <p class="text-xs text-on-surface-variant leading-snug">
                    Relay is in manual fallback mode for one or more repositories.
                </p>
            @endif

            @if ($needsPermissionRepos->isNotEmpty() || $errorRepos->isNotEmpty() || $manualRepos->isNotEmpty())
                <ul class="space-y-1">
                    @foreach ($selectedRepos as $repoFullName)
                        @php
                            $repoState = $managedWebhookStates[$repoFullName]['state'] ?? 'manual';
                            $repoReason = $managedWebhookStates[$repoFullName]['reason'] ?? null;
                        @endphp
                        @if (in_array($repoState, ['needs_permission', 'error', 'manual'], true))
                            <li class="text-[11px] text-on-surface-variant">
                                <span class="font-mono">{{ $repoFullName }}</span>
                                <span class="uppercase text-[9px] tracking-wider">({{ str_replace('_', ' ', $repoState) }})</span>
                                @if ($repoReason)
                                    <span> — {{ $repoReason }}</span>
                                @endif
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endif

            @if ($webhookState !== 'managed')
                <details class="rounded-md bg-surface-container-high px-2 py-1.5">
                    <summary class="cursor-pointer font-label text-[10px] text-outline uppercase tracking-wider">
                        Manual setup fallback
                    </summary>
                    <div class="mt-2 space-y-1.5">
                        <input type="text" readonly
                               value="{{ $webhookUrl }}"
                               class="w-full bg-surface-container-highest text-on-surface-variant rounded px-2 py-1 font-mono text-[10px] tracking-wider"
                               onclick="this.select()">
                        <input type="text" readonly
                               value="{{ $source->webhook_secret }}"
                               class="w-full bg-surface-container-highest text-on-surface-variant rounded px-2 py-1 font-mono text-[10px] tracking-wider"
                               onclick="this.select()">
                    </div>
                </details>
            @endif
        @else
            @if ($webhookState === 'managed')
                <p class="text-xs text-secondary leading-snug">
                    Relay is managing a dynamic Jira webhook for {{ count($jiraProjects) }} {{ \Illuminate\Support\Str::plural('project', count($jiraProjects)) }}.
                </p>
            @elseif ($webhookState === 'needs_permission')
                <p class="text-xs text-error leading-snug">
                    Relay could not manage the Jira webhook due to missing permissions. Reconnect Jira with webhook management scopes.
                </p>
            @elseif ($webhookState === 'error')
                <p class="text-xs text-error leading-snug">
                    Relay could not finish Jira webhook setup. Check the error below and retry sync.
                </p>
            @elseif ($webhookState === 'unconfigured')
                <p class="text-xs text-on-surface-variant leading-snug">
                    Pick projects first and Relay will provision a dynamic webhook automatically.
                </p>
            @else
                <p class="text-xs text-on-surface-variant leading-snug">
                    Relay is in manual fallback mode for Jira. Paste the URL below into Jira's system webhook settings.
                </p>
            @endif

            @if (($jiraWebhookMeta['reason'] ?? null) && $webhookState !== 'managed')
                <p class="text-[11px] text-on-surface-variant leading-snug">
                    {{ $jiraWebhookMeta['reason'] }}
                </p>
            @endif

            @if ($webhookState !== 'managed')
                <details class="rounded-md bg-surface-container-high px-2 py-1.5">
                    <summary class="cursor-pointer font-label text-[10px] text-outline uppercase tracking-wider">
                        Manual setup fallback
                    </summary>
                    <div class="mt-2 space-y-1.5">
                        <input type="text" readonly
                               value="{{ $jiraManualUrl }}"
                               class="w-full bg-surface-container-highest text-on-surface-variant rounded px-2 py-1 font-mono text-[10px] tracking-wider"
                               onclick="this.select()">
                    </div>
                </details>
            @endif
        @endif

        @if ($source->webhook_last_error)
            <div class="rounded-md bg-error-container/20 border-l-2 border-error px-2 py-1.5">
                <p class="text-xs text-error leading-snug">{{ $source->webhook_last_error }}</p>
            </div>
        @endif
    </section>

    {{-- Intake rules section --}}
    <section class="bg-surface-container-low rounded-xl p-4 space-y-1.5">
        <div class="flex items-center justify-between">
            <h2 class="font-label text-[10px] text-outline uppercase tracking-widest">Intake Rules</h2>
            <a href="{{ route('intake.rules.edit', $source) }}" class="font-label text-[10px] text-primary uppercase tracking-wider hover:underline">
                {{ $rule && ($rule->include_labels || $rule->exclude_labels || $rule->auto_accept_labels || $rule->unassigned_only) ? 'Edit' : 'Add' }} →
            </a>
        </div>

        @php
            $hasAny = $rule && ($rule->include_labels || $rule->exclude_labels || $rule->auto_accept_labels || $rule->unassigned_only);
        @endphp
        @if ($hasAny)
            @if (! empty($rule->include_labels))
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-label text-[10px] text-outline uppercase tracking-wider">Include</span>
                    @foreach ($rule->include_labels as $label)
                        <span class="inline-flex items-center rounded bg-stage-verify/20 text-stage-verify px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">{{ $label }}</span>
                    @endforeach
                </div>
            @endif
            @if (! empty($rule->exclude_labels))
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-label text-[10px] text-outline uppercase tracking-wider">Exclude</span>
                    @foreach ($rule->exclude_labels as $label)
                        <span class="inline-flex items-center rounded bg-error-container/30 text-error px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">{{ $label }}</span>
                    @endforeach
                </div>
            @endif
            @if (! empty($rule->auto_accept_labels))
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-label text-[10px] text-outline uppercase tracking-wider">Auto-Accept</span>
                    @foreach ($rule->auto_accept_labels as $label)
                        <span class="inline-flex items-center rounded bg-primary-container/30 text-primary px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">{{ $label }}</span>
                    @endforeach
                </div>
            @endif
            @if ($rule->unassigned_only)
                <p class="font-label text-[10px] text-outline uppercase tracking-wider">
                    Unassigned only
                </p>
            @endif
        @else
            <p class="font-label text-[10px] text-outline uppercase tracking-wider">
                No intake rules · all issues accepted
            </p>
        @endif
    </section>

    {{-- Components → repos (Jira only) --}}
    @if ($source->type->value === 'jira')
        <section class="bg-surface-container-low rounded-xl p-4 space-y-1.5">
            <div class="flex items-center justify-between">
                <h2 class="font-label text-[10px] text-outline uppercase tracking-widest">Components → Repos</h2>
                <a href="{{ route('components.index', $source) }}" class="font-label text-[10px] text-primary uppercase tracking-wider hover:underline">
                    Map →
                </a>
            </div>
            @if ($componentCount === 0)
                <p class="font-label text-[10px] text-outline uppercase tracking-wider">
                    None yet · discovered on next sync
                </p>
            @else
                <p class="font-label text-[10px] {{ $componentsWithRepos < $componentCount ? 'text-stage-stuck' : 'text-outline' }} uppercase tracking-wider">
                    {{ $componentsWithRepos }} / {{ $componentCount }} mapped
                </p>
            @endif
        </section>
    @endif

    {{-- Danger zone --}}
    <section class="bg-surface-container-low rounded-xl p-4 space-y-3">
        <h2 class="font-label text-[10px] text-error uppercase tracking-widest">Danger Zone</h2>
        <form method="POST" action="{{ route('oauth.disconnect', $source->type->value) }}" class="contents"
              onsubmit="return confirm('Disconnect this source? This revokes access and removes associated data.')">
            @csrf @method('DELETE')
            <button type="submit" class="font-label text-[10px] uppercase tracking-widest leading-none text-error hover:underline">
                Disconnect this source
            </button>
        </form>
    </section>
</div>
