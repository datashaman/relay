<?php

use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Jobs\SyncSourceIssuesJob;
use App\Models\Issue;
use App\Models\OauthToken;
use App\Models\Repository;
use App\Models\Source;
use App\Services\GitHubClient;
use App\Services\JiraClient;
use App\Services\OauthService;
use App\Services\OrchestratorService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Intake Control')]
#[Layout('layouts::app')]
class extends Component
{
    /** source id → flash message for the last test-connection call */
    public array $testResults = [];

    #[On('echo-private:intake,.IntakeQueueChanged')]
    public function handleIntakeQueueChanged(): void
    {
        // Re-render handled automatically by Livewire when this method is called.
    }

    #[On('echo-private:intake,.SourceSynced')]
    public function handleSourceSynced(): void
    {
        // Re-render handled automatically by Livewire when this method is called.
    }

    /** source id → flash message for the last sync-now call */
    public array $syncResults = [];

    /** Whether to show archived issues in the queue. */
    public bool $showArchived = false;

    public function syncNow(int $sourceId): void
    {
        $source = Source::findOrFail($sourceId);
        SyncSourceIssuesJob::dispatch($source);
        $this->syncResults[$sourceId] = ['ok' => true, 'label' => 'Queued ✓'];
    }

    public function testConnection(int $sourceId, OauthService $oauth): void
    {
        $source = Source::findOrFail($sourceId);
        $token = OauthToken::query()
            ->where('source_id', $source->id)
            ->where('provider', $source->type->value)
            ->first();

        if (! $token) {
            $this->testResults[$sourceId] = ['ok' => false, 'label' => 'No token ✗'];

            return;
        }

        try {
            $token = $oauth->refreshIfExpired($token);

            if ($source->type->value === 'github') {
                (new GitHubClient($token, $oauth))->listRepos(page: 1, perPage: 1);
            } elseif ($source->type->value === 'jira') {
                (new JiraClient($token, $oauth, $source))->listProjects();
            }

            $this->testResults[$sourceId] = ['ok' => true, 'label' => 'OK ✓'];
        } catch (Throwable $e) {
            $this->testResults[$sourceId] = ['ok' => false, 'label' => 'Failed ✗'];
        }
    }

    public function archiveIssue(int $issueId, string $reason = ''): void
    {
        $issue = Issue::findOrFail($issueId);

        if ($issue->runs()->whereIn('status', [RunStatus::Pending, RunStatus::Running, RunStatus::Stuck])->exists()) {
            session()->flash('error', 'Cancel the in-flight run before archiving this issue.');

            return;
        }

        $issue->archive($reason !== '' ? $reason : null);
        session()->flash('success', "Issue \"{$issue->title}\" archived.");
    }

    public function unarchiveIssue(int $issueId): void
    {
        $issue = Issue::findOrFail($issueId);
        $issue->unarchive();
        session()->flash('success', "Issue \"{$issue->title}\" unarchived.");
    }

    public function acceptIssue(int $issueId, OrchestratorService $orchestrator, ?int $repositoryId = null): void
    {
        $issue = Issue::findOrFail($issueId);

        if ($issue->status !== IssueStatus::Queued) {
            session()->flash('error', 'Only queued issues can be accepted.');

            return;
        }

        $repository = null;

        if ($repositoryId !== null) {
            $component = $issue->component;

            if ($component) {
                $repository = $component->repositories()->whereKey($repositoryId)->first();
            } else {
                $repository = Repository::whereKey($repositoryId)->first();
            }

            if (! $repository) {
                session()->flash('error', 'Selected repository is not available for this issue.');

                return;
            }
        } elseif ($issue->component_id && ! $issue->repository_id) {
            session()->flash('error', 'Pick a repository to start this issue on.');

            return;
        }

        $orchestrator->startRun($issue, $repository);

        session()->flash('success', "Issue \"{$issue->title}\" accepted. Preflight starting.");
    }

    public function rejectIssue(int $issueId): void
    {
        $issue = Issue::findOrFail($issueId);

        if ($issue->status !== IssueStatus::Queued) {
            session()->flash('error', 'Only queued issues can be rejected.');

            return;
        }

        $issue->update(['status' => IssueStatus::Rejected]);
        session()->flash('success', "Issue \"{$issue->title}\" rejected.");
    }

    public function with(): array
    {
        $sources = Source::with('filterRule')->orderBy('name')->get();

        $sources->each->ensureWebhookSecret();

        $sourceIds = $sources->pluck('id')->all();

        /** @var array<int, int> $sourcesQueuedCounts */
        $sourcesQueuedCounts = Issue::active()
            ->where('status', IssueStatus::Queued)
            ->whereIn('source_id', $sourceIds)
            ->selectRaw('source_id, count(*) as queued_count')
            ->groupBy('source_id')
            ->pluck('queued_count', 'source_id')
            ->toArray();

        $incomingQuery = Issue::with(['source', 'component.repositories', 'repository'])
            ->orderByDesc('created_at')
            ->limit(15);

        if ($this->showArchived) {
            $incomingQuery->archived();
        } else {
            $incomingQuery->active()->where('status', IssueStatus::Queued);
        }

        return [
            'sources' => $sources,
            'pausedCount' => $sources->where('is_intake_paused', true)->count(),
            'connectedCount' => $sources->where('is_active', true)->count(),
            'sourcesQueuedCounts' => $sourcesQueuedCounts,
            'incoming' => $incomingQuery->get(),
            'pendingCount' => Issue::active()->where('status', IssueStatus::Queued)->count(),
        ];
    }
};
?>

<div class="space-y-6">
    {{-- Header --}}
    <div>
        <span class="font-label text-[10px] text-outline uppercase tracking-widest">System Configuration</span>
        <h1 class="font-headline text-3xl font-bold text-on-surface mt-1">Intake Control</h1>
    </div>

    {{-- Intake Status --}}
    <section class="flex items-center justify-between bg-surface-container-low p-4 rounded-xl">
        <div class="flex flex-col">
            <span class="font-label text-[10px] text-outline uppercase tracking-widest">Intake Status</span>
            <span class="font-label text-xs uppercase tracking-widest {{ $pausedCount === 0 ? 'text-secondary' : 'text-stage-stuck' }}">
                @if ($pausedCount === 0)
                    Active · {{ $connectedCount }} {{ \Illuminate\Support\Str::plural('source', $connectedCount) }}
                @else
                    {{ $pausedCount }} / {{ $sources->count() }} paused
                @endif
            </span>
        </div>
        <span class="font-label text-[10px] text-outline uppercase tracking-widest">Toggle per source below</span>
    </section>

    {{-- Connected Sources --}}
    <section class="space-y-3">
        <div class="flex items-center justify-between gap-2 flex-wrap px-1">
            <h2 class="font-headline text-xl text-on-surface">Connected Sources</h2>
            <div class="flex items-center gap-2">
                <a href="{{ route('oauth.redirect', 'github') }}"
                   class="rounded-md bg-surface-container-high text-on-surface px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest">
                    Connect GitHub
                </a>
                <a href="{{ route('oauth.redirect', 'jira') }}"
                   class="rounded-md bg-primary text-on-primary px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">
                    Connect Jira
                </a>
            </div>
        </div>

        @if ($sources->isEmpty())
            <div class="bg-surface-container-low rounded-xl p-8 text-center">
                <p class="text-on-surface-variant">No sources connected yet.</p>
                <p class="font-label text-[10px] text-outline uppercase tracking-widest mt-1">
                    Use the buttons above to connect GitHub or Jira
                </p>
            </div>
        @else
            <div class="space-y-2">
            @foreach ($sources as $source)
                @php
                    $isConnected = $source->is_active;
                    $isPaused = $source->is_intake_paused;
                    $typePillClass = $source->type->value === 'github'
                        ? 'bg-surface-container-high text-on-surface'
                        : 'bg-primary-container/30 text-primary';
                    $testFlash = $testResults[$source->id] ?? null;
                    $syncFlash = $syncResults[$source->id] ?? null;

                    // Health pill: green = active + no sync error + webhook ok; amber = sync error or partial webhook failure; red = disconnected
                    $selectedRepos = $source->config['repositories'] ?? [];
                    $managedWebhookStates = $source->config['managed_webhooks'] ?? [];
                    $repoStates = collect($selectedRepos)->mapWithKeys(fn ($repo) => [$repo => $managedWebhookStates[$repo]['state'] ?? null]);
                    $webhookBad = $repoStates->filter(fn ($s) => in_array($s, ['needs_permission', 'error'], true))->isNotEmpty();
                    $jiraWebhookMeta = $source->config['managed_jira_webhook'] ?? null;
                    if ($source->type->value === 'jira') {
                        $webhookBad = in_array($jiraWebhookMeta['state'] ?? null, ['needs_permission', 'error'], true);
                    }

                    if (! $isConnected) {
                        $healthPillClass = 'bg-error-container/30 text-error';
                        $healthLabel = 'Disconnected';
                    } elseif ($source->sync_error || $webhookBad) {
                        $healthPillClass = 'bg-stage-stuck/20 text-stage-stuck';
                        $healthLabel = 'Degraded';
                    } else {
                        $healthPillClass = 'bg-secondary-container/30 text-secondary';
                        $healthLabel = 'Healthy';
                    }

                    $scopeCount = $source->type->value === 'github'
                        ? count($source->config['repositories'] ?? [])
                        : count($source->config['projects'] ?? []);
                    $scopeLabel = $source->type->value === 'github'
                        ? \Illuminate\Support\Str::plural('repo', $scopeCount)
                        : \Illuminate\Support\Str::plural('project', $scopeCount);
                    $queuedCount = $sourcesQueuedCounts[$source->id] ?? 0;

                    $statusParts = [];
                    if ($source->last_synced_at) {
                        $statusParts[] = 'synced ' . $source->last_synced_at->diffForHumans(null, true) . ' ago';
                    }
                    $statusParts[] = $scopeCount . ' ' . $scopeLabel;
                    $statusParts[] = $queuedCount . ' queued';
                    $statusLine = implode(' · ', $statusParts);
                @endphp
                <div class="bg-surface-container-low rounded-xl p-4" wire:key="source-{{ $source->id }}">
                    <div class="flex items-center gap-3 flex-wrap">
                        {{-- Type badge --}}
                        <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 {{ $typePillClass }} font-label text-[10px] uppercase tracking-wider">
                            <x-source-icon :type="$source->type->value" class="w-3 h-3" />
                            {{ $source->type->value }}
                        </span>

                        {{-- Source name --}}
                        <span class="text-sm font-semibold text-on-surface">{{ $source->external_account }}</span>

                        @if ($isPaused)
                            <span class="inline-flex items-center rounded bg-stage-stuck/20 text-stage-stuck px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">
                                Paused
                            </span>
                        @endif

                        {{-- Health pill --}}
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider {{ $healthPillClass }}">
                            {{ $healthLabel }}
                        </span>

                        {{-- Status line --}}
                        <span class="font-label text-[10px] text-outline uppercase tracking-widest">
                            {{ $statusLine }}
                        </span>

                        {{-- Actions --}}
                        <div class="flex items-center gap-3 ml-auto leading-none">
                            @if ($isConnected)
                                <button type="button" wire:click="syncNow({{ $source->id }})"
                                        class="font-label text-[10px] uppercase tracking-widest leading-none {{ $syncFlash ? ($syncFlash['ok'] ? 'text-secondary' : 'text-error') : 'text-secondary' }} hover:underline">
                                    <span wire:loading.remove wire:target="syncNow({{ $source->id }})">{{ $syncFlash['label'] ?? 'Sync Now' }}</span>
                                    <span wire:loading wire:target="syncNow({{ $source->id }})">Syncing…</span>
                                </button>
                                <button type="button" wire:click="testConnection({{ $source->id }})"
                                        class="font-label text-[10px] uppercase tracking-widest leading-none {{ $testFlash ? ($testFlash['ok'] ? 'text-secondary' : 'text-error') : 'text-primary' }} hover:underline">
                                    <span wire:loading.remove wire:target="testConnection({{ $source->id }})">{{ $testFlash['label'] ?? 'Test' }}</span>
                                    <span wire:loading wire:target="testConnection({{ $source->id }})">Testing…</span>
                                </button>
                            @endif
                            <a href="{{ route('intake.sources.show', $source) }}"
                               class="font-label text-[10px] uppercase tracking-widest leading-none text-primary hover:underline">
                                Manage →
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
            </div>
        @endif
    </section>

    {{-- Incoming Queue --}}
    <section class="space-y-3">
        <div class="flex items-center justify-between px-1">
            <h2 class="font-headline text-xl text-on-surface">Incoming Queue</h2>
            <div class="flex items-center gap-3">
                <button type="button" wire:click="$toggle('showArchived')"
                        class="font-label text-[10px] uppercase tracking-widest {{ $showArchived ? 'text-stage-stuck' : 'text-outline' }} hover:underline">
                    {{ $showArchived ? 'Hide Archived' : 'Show Archived' }}
                </button>
                @if (! $showArchived)
                    <span class="font-label text-[10px] text-outline uppercase tracking-widest">
                        Pending {{ str_pad((string) $pendingCount, 2, '0', STR_PAD_LEFT) }}
                    </span>
                @endif
            </div>
        </div>

        @if ($incoming->isEmpty())
            <div class="bg-surface-container-low rounded-xl p-8 text-center">
                @if ($showArchived)
                    <p class="text-on-surface-variant">No archived issues.</p>
                @else
                    <p class="text-on-surface-variant">No incoming issues.</p>
                    <p class="font-label text-[10px] text-outline uppercase tracking-widest mt-1">
                        Next sync will pull any new items
                    </p>
                @endif
            </div>
        @else
            @foreach ($incoming as $issue)
                @php
                    $externalRef = $issue->source->type->value === 'jira'
                        ? $issue->external_id
                        : 'GH-' . $issue->external_id;
                @endphp
                <div class="bg-surface-container-low rounded-xl p-4" wire:key="issue-{{ $issue->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0 space-y-2">
                            <div class="flex items-center gap-2 flex-wrap font-label text-[10px] uppercase tracking-widest">
                                <span class="flex items-center gap-1 text-outline">
                                    <x-source-icon :type="$issue->source->type->value" class="w-3 h-3" />
                                    {{ $issue->source->type->value }}
                                </span>
                                <span class="text-outline-variant">·</span>
                                <span class="text-primary">{{ $externalRef }}</span>
                                @if ($issue->archived_at)
                                    <span class="text-outline-variant">·</span>
                                    <span class="inline-flex items-center rounded bg-stage-stuck/20 text-stage-stuck px-1.5 py-0.5 tracking-wider">Archived {{ $issue->archived_at->diffForHumans(null, true) }} ago</span>
                                @endif
                                @if ($issue->raw_status)
                                    <span class="text-outline-variant">·</span>
                                    <span class="inline-flex items-center rounded bg-secondary-container text-on-secondary-container px-1.5 py-0.5 tracking-wider">{{ $issue->raw_status }}</span>
                                @endif
                                @if ($issue->auto_accepted)
                                    <span class="text-outline-variant">·</span>
                                    <span class="text-primary">Auto-Accept</span>
                                @endif
                                <span class="text-outline-variant">·</span>
                                <span class="text-outline">{{ $issue->created_at->diffForHumans(null, true) }} ago</span>
                            </div>

                            <h3 class="text-sm font-semibold text-on-surface leading-snug">{{ $issue->title }}</h3>

                            @if ($issue->archived_reason)
                                <p class="text-xs text-stage-stuck italic">Reason: {{ $issue->archived_reason }}</p>
                            @endif

                            @if ($issue->body)
                                <div>
                                    <input type="checkbox" id="body-{{ $issue->id }}" class="peer sr-only">
                                    <p class="text-xs text-on-surface-variant line-clamp-2 peer-checked:line-clamp-none whitespace-pre-line">{{ $issue->body }}</p>
                                    <label for="body-{{ $issue->id }}" class="cursor-pointer inline-block font-label text-[10px] text-primary uppercase tracking-widest hover:underline mt-1 peer-checked:hidden">Show more ↓</label>
                                    <label for="body-{{ $issue->id }}" class="cursor-pointer hidden font-label text-[10px] text-primary uppercase tracking-widest hover:underline mt-1 peer-checked:inline-block">Show less ↑</label>
                                </div>
                            @endif

                            @if (! empty($issue->labels))
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($issue->labels as $label)
                                        <span class="inline-flex items-center rounded bg-surface-container-high text-on-surface-variant px-2 py-0.5 font-label text-[10px] uppercase tracking-widest">
                                            {{ $label }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="shrink-0 flex flex-col gap-1 min-w-[9rem]">
                            @if ($showArchived)
                                <button type="button" wire:click="unarchiveIssue({{ $issue->id }})"
                                        class="w-full rounded-md bg-primary text-on-primary px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">
                                    Unarchive
                                </button>
                            @else
                                @php
                                    $componentRepos = $issue->component?->repositories ?? collect();
                                    $hasDirectRepo = (bool) $issue->repository_id;
                                @endphp

                                @if ($hasDirectRepo)
                                    <button type="button" wire:click="acceptIssue({{ $issue->id }})"
                                            class="w-full rounded-md bg-primary text-on-primary px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">
                                        Accept
                                    </button>
                                @elseif ($issue->component_id && $componentRepos->isNotEmpty())
                                    <span class="font-label text-[10px] text-outline uppercase tracking-wider text-right">
                                        Start on · {{ $issue->component->name }}
                                    </span>
                                    @foreach ($componentRepos as $repo)
                                        <button type="button" wire:click="acceptIssue({{ $issue->id }}, {{ $repo->id }})"
                                                class="w-full rounded-md bg-primary text-on-primary px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90 font-mono truncate"
                                                title="{{ $repo->name }}">
                                            {{ $repo->name }}
                                        </button>
                                    @endforeach
                                @elseif ($issue->component_id)
                                    <span class="rounded-md bg-stage-stuck/20 text-stage-stuck px-3 py-1.5 font-label text-[10px] uppercase tracking-widest text-center">
                                        No repos for {{ $issue->component->name }}
                                    </span>
                                    <a href="{{ route('components.index', $issue->source) }}"
                                       class="w-full rounded-md bg-surface-container-high text-on-surface px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest text-center">
                                        Map repos →
                                    </a>
                                @else
                                    <span class="rounded-md bg-stage-stuck/20 text-stage-stuck px-3 py-1.5 font-label text-[10px] uppercase tracking-widest text-center">
                                        No component
                                    </span>
                                @endif

                                <button type="button"
                                        x-data
                                        x-on:click="$wire.archiveIssue({{ $issue->id }}, prompt('Archive reason (optional):') ?? '')"
                                        class="w-full rounded-md bg-surface-container-high text-on-surface px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest">
                                    Archive
                                </button>

                                <button type="button" wire:click="rejectIssue({{ $issue->id }})"
                                        class="w-full rounded-md bg-surface-container-high text-on-surface px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest">
                                    Reject
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        @endif
    </section>
</div>
