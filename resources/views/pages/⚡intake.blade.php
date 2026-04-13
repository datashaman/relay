<?php

use App\Enums\IssueStatus;
use App\Jobs\SyncSourceIssuesJob;
use App\Models\Issue;
use App\Models\Source;
use App\Services\GitHubClient;
use App\Services\JiraClient;
use App\Services\OauthService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Intake Control')]
#[Layout('layouts::app')]
class extends Component {
    /** source id → flash message for the last test-connection call */
    public array $testResults = [];

    /** source id → flash message for the last sync-now call */
    public array $syncResults = [];

    public function togglePause(int $sourceId): void
    {
        $source = Source::findOrFail($sourceId);
        $source->update(['is_intake_paused' => ! $source->is_intake_paused]);
    }

    public function syncNow(int $sourceId): void
    {
        $source = Source::findOrFail($sourceId);
        SyncSourceIssuesJob::dispatch($source);
        $this->syncResults[$sourceId] = ['ok' => true, 'label' => 'Queued ✓'];
    }

    public function testConnection(int $sourceId, OauthService $oauth): void
    {
        $source = Source::findOrFail($sourceId);
        $token = $source->oauthTokens()->where('provider', $source->type->value)->first();

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
        } catch (\Throwable $e) {
            $this->testResults[$sourceId] = ['ok' => false, 'label' => 'Failed ✗'];
        }
    }

    public function acceptIssue(int $issueId): void
    {
        $issue = Issue::findOrFail($issueId);

        if ($issue->status !== IssueStatus::Queued) {
            session()->flash('error', 'Only queued issues can be accepted.');

            return;
        }

        $issue->update(['status' => IssueStatus::Accepted]);
        session()->flash('success', "Issue \"{$issue->title}\" accepted into preflight.");
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

        return [
            'sources' => $sources,
            'pausedCount' => $sources->where('is_intake_paused', true)->count(),
            'connectedCount' => $sources->where('is_active', true)->count(),
            'incoming' => Issue::with('source')
                ->where('status', IssueStatus::Queued)
                ->orderByDesc('created_at')
                ->limit(15)
                ->get(),
            'pendingCount' => Issue::where('status', IssueStatus::Queued)->count(),
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
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @foreach ($sources as $source)
                @php
                    $isConnected = $source->is_active;
                    $isPaused = $source->is_intake_paused;
                    $typePillClass = $source->type->value === 'github'
                        ? 'bg-surface-container-high text-on-surface'
                        : 'bg-primary-container/30 text-primary';
                    $testFlash = $testResults[$source->id] ?? null;
                    $syncFlash = $syncResults[$source->id] ?? null;
                @endphp
                <div class="bg-surface-container-low rounded-xl p-4" wire:key="source-{{ $source->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5 flex-wrap mb-1">
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 {{ $typePillClass }} font-label text-[10px] uppercase tracking-wider">
                                    {{ $source->type->value }}
                                </span>
                                @if ($isConnected)
                                    <span class="inline-flex items-center rounded bg-secondary-container/30 text-secondary px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">
                                        Connected
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded bg-surface-container-high text-outline px-1.5 py-0.5 font-label text-[10px] uppercase tracking-wider">
                                        Disconnected
                                    </span>
                                @endif
                                @if ($isPaused)
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
                            <h3 class="flex items-center gap-2 text-sm font-semibold text-on-surface leading-tight">
                                <span class="text-on-surface-variant">
                                    <x-source-icon :type="$source->type->value" />
                                </span>
                                {{ $source->name }}
                            </h3>
                            <p class="font-label text-[10px] text-outline mt-0.5">
                                <span class="whitespace-nowrap">{{ $source->external_account }}</span>
                                @if ($source->last_synced_at)
                                    <span class="text-outline-variant">·</span>
                                    <span class="whitespace-nowrap">synced {{ $source->last_synced_at->diffForHumans(null, true) }} ago</span>
                                @endif
                            </p>
                            @if ($source->sync_error)
                                <div class="mt-2 rounded-md bg-error-container/20 border-l-2 border-error px-2 py-1.5">
                                    <p class="text-xs text-error leading-snug">{{ $source->sync_error }}</p>
                                    @if ($source->next_retry_at)
                                        <p class="font-label text-[10px] text-outline uppercase tracking-wider mt-1">
                                            Retry {{ $source->next_retry_at->diffForHumans() }}
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @if ($isConnected)
                            <button type="button" wire:click="togglePause({{ $source->id }})"
                                    class="rounded-md px-3 py-1.5 font-label text-[10px] uppercase tracking-wider {{ $isPaused ? 'bg-primary text-on-primary hover:bg-primary/90' : 'bg-surface-container-high text-on-surface hover:bg-surface-container-highest' }}">
                                {{ $isPaused ? 'Resume' : 'Pause' }}
                            </button>
                        @endif
                    </div>

                    {{-- Repositories (GitHub only) --}}
                    @if ($source->type->value === 'github')
                        @php $repos = $source->config['repositories'] ?? []; @endphp
                        <div class="mt-3 pt-3 border-t border-outline-variant/20 space-y-1.5">
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
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    @foreach ($repos as $repoName)
                                        <span class="inline-flex items-center rounded bg-surface-container-high text-on-surface-variant px-1.5 py-0.5 font-label text-[10px] tracking-wider font-mono">
                                            {{ $repoName }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Filter rules summary --}}
                    @php $rule = $source->filterRule; @endphp
                    <div class="mt-3 pt-3 border-t border-outline-variant/20 space-y-1.5">
                        <div class="flex items-center justify-between">
                            <span class="font-label text-[10px] text-outline uppercase tracking-wider">Intake Rules</span>
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
                    </div>

                    {{-- Source actions --}}
                    <div class="flex items-center gap-3 mt-3 pt-2 border-t border-outline-variant/20 leading-none">
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
                        <form method="POST" action="{{ route('oauth.disconnect', $source->type->value) }}" class="contents ml-auto"
                              onsubmit="return confirm('Disconnect this source? This revokes access and removes associated data.')">
                            @csrf @method('DELETE')
                            <button type="submit" class="font-label text-[10px] uppercase tracking-widest leading-none text-error hover:underline">
                                Disconnect
                            </button>
                        </form>
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
            <span class="font-label text-[10px] text-outline uppercase tracking-widest">
                Pending {{ str_pad((string) $pendingCount, 2, '0', STR_PAD_LEFT) }}
            </span>
        </div>

        @if ($incoming->isEmpty())
            <div class="bg-surface-container-low rounded-xl p-8 text-center">
                <p class="text-on-surface-variant">No incoming issues.</p>
                <p class="font-label text-[10px] text-outline uppercase tracking-widest mt-1">
                    Next sync will pull any new items
                </p>
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
                                @if ($issue->auto_accepted)
                                    <span class="text-outline-variant">·</span>
                                    <span class="text-primary">Auto-Accept</span>
                                @endif
                                <span class="text-outline-variant">·</span>
                                <span class="text-outline">{{ $issue->created_at->diffForHumans(null, true) }} ago</span>
                            </div>

                            <h3 class="text-sm font-semibold text-on-surface leading-snug">{{ $issue->title }}</h3>

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

                        <div class="shrink-0 flex flex-col gap-1">
                            <button type="button" wire:click="acceptIssue({{ $issue->id }})"
                                    class="w-full rounded-md bg-primary text-on-primary px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">
                                Accept
                            </button>
                            <button type="button" wire:click="rejectIssue({{ $issue->id }})"
                                    class="w-full rounded-md bg-surface-container-high text-on-surface px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest">
                                Reject
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        @endif
    </section>
</div>
