@extends('layouts.app')

@section('title', $activeIssue ? $activeIssue->title : 'Issues')
@section('container_class', 'max-w-7xl')

@section('content')
    @if ($issues->isEmpty())
        <div class="text-center text-on-surface-variant py-16">
            <p class="text-lg">No pipeline issues yet.</p>
            <p class="text-sm mt-1">Accept issues from the <a href="{{ route('intake.index') }}" class="text-primary hover:underline">queue</a> to see them here.</p>
        </div>
    @else
        {{-- Mobile-only issue switcher: opens an inline sheet listing other issues --}}
        <details class="lg:hidden rounded-xl bg-surface-container-low mb-3 group">
            <summary class="flex items-center justify-between px-4 py-3 cursor-pointer list-none">
                <span class="flex items-center gap-2">
                    <span class="font-label text-[10px] text-outline uppercase tracking-widest">All Issues</span>
                    <span class="font-label text-[10px] text-on-surface-variant">({{ $issues->count() }})</span>
                </span>
                <span class="font-label text-[10px] text-primary uppercase tracking-widest group-open:hidden">Switch ↓</span>
                <span class="font-label text-[10px] text-primary uppercase tracking-widest hidden group-open:inline">Close ↑</span>
            </summary>
            <div class="border-t border-outline-variant/40 max-h-72 overflow-y-auto">
                @foreach ($issues as $listIssue)
                    @php $isActive = $activeIssue && $listIssue->id === $activeIssue->id; @endphp
                    <a href="{{ route('issues.show', $listIssue) }}"
                       class="block px-4 py-2.5 border-b border-outline-variant/20 {{ $isActive ? 'bg-surface-container-high border-l-2 border-l-secondary' : '' }}">
                        <div class="text-sm font-medium text-on-surface line-clamp-1">{{ $listIssue->title }}</div>
                        <div class="text-xs text-on-surface-variant mt-0.5">
                            {{ str_replace('_', ' ', ucfirst($listIssue->status->value)) }}
                        </div>
                    </a>
                @endforeach
            </div>
        </details>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 min-h-[calc(100vh-10rem)]" id="issue-view">
            {{-- Left panel: Issue queue (desktop only) --}}
            <div class="hidden lg:flex lg:col-span-3 rounded-xl bg-surface-container-low overflow-hidden flex-col">
                <div class="px-3 py-2 border-b border-outline-variant/40 bg-surface-container">
                    <h2 class="text-sm font-headline font-semibold text-on-surface-variant">Pipeline Issues</h2>
                </div>
                <div class="overflow-y-auto flex-1" id="issue-list">
                    @foreach ($issues as $listIssue)
                        @php
                            $isActive = $activeIssue && $listIssue->id === $activeIssue->id;
                            $listRun = $listIssue->runs->first();
                            $listStage = $listRun?->stages->first();
                        @endphp
                        <a href="{{ route('issues.show', $listIssue) }}"
                           data-issue-id="{{ $listIssue->id }}"
                           class="block px-3 py-2.5 border-b border-outline-variant/30 hover:bg-surface-container transition-colors {{ $isActive ? 'bg-surface-container-high border-l-2 border-l-secondary' : '' }}">
                            <div class="text-sm font-medium text-on-surface truncate">{{ $listIssue->title }}</div>
                            <div class="flex items-center gap-2 mt-1">
                                @php $statusColors = match($listIssue->status->value) {
                                    'accepted' => 'bg-secondary-container/30 text-secondary',
                                    'in_progress' => 'bg-primary-container/30 text-primary',
                                    'completed' => 'bg-secondary-container/30 text-secondary',
                                    'failed' => 'bg-error-container/30 text-error',
                                    'stuck' => 'bg-stage-stuck/20 text-stage-stuck',
                                    default => 'bg-surface-container-high text-on-surface-variant',
                                }; @endphp
                                <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium {{ $statusColors }}">
                                    {{ str_replace('_', ' ', ucfirst($listIssue->status->value)) }}
                                </span>
                                @if ($listStage)
                                    <span class="text-xs text-on-surface-variant">{{ ucfirst($listStage->name->value) }}</span>
                                @endif
                                @if ($listRun && $listRun->iteration > 1)
                                    <span class="text-xs text-primary">↺ {{ $listRun->iteration }}</span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Center panel: Active issue details with live progress --}}
            <div class="order-2 lg:order-none lg:col-span-6 rounded-xl bg-surface-container-low overflow-hidden flex flex-col">
                @if ($activeIssue)
                    <div class="px-4 py-3 border-b border-outline-variant/40 bg-surface-container">
                        <h2 class="text-base font-headline font-semibold text-on-surface">{{ $activeIssue->title }}</h2>
                        <div class="flex items-center gap-2 mt-1 text-xs text-on-surface-variant">
                            @if ($activeIssue->external_id)
                                <span>{{ $activeIssue->external_id }}</span>
                            @endif
                            @if ($activeIssue->external_url)
                                <a href="{{ $activeIssue->external_url }}" target="_blank" class="text-primary hover:underline">External →</a>
                            @endif
                            @if ($latestRun)
                                <span>·</span>
                                <a href="{{ route('runs.timeline', $latestRun) }}" class="text-primary hover:underline">Full Timeline</a>
                            @endif
                        </div>
                    </div>

                    @if ($latestRun)
                        {{-- Stage pipeline indicator --}}
                        <div class="px-4 py-3 border-b border-outline-variant/40" id="stage-pipeline">
                            @include('issues._stage-pipeline', ['run' => $latestRun])
                        </div>
                    @endif

                    <div class="overflow-y-auto flex-1 p-4 space-y-4" id="live-progress-content">
                        @if ($latestRun)
                            {{-- Preflight doc --}}
                            @if ($latestRun->preflight_doc)
                                <details open>
                                    <summary class="cursor-pointer text-sm font-semibold text-on-surface-variant">Preflight Doc</summary>
                                    <div class="mt-2 prose prose-sm dark:prose-invert max-w-none bg-surface-container rounded-xl p-3 text-xs whitespace-pre-wrap">{{ $latestRun->preflight_doc }}</div>
                                </details>
                            @endif

                            {{-- Live agent activity (tool calls) --}}
                            @php
                                $currentStageToolCalls = $currentStage?->status === \App\Enums\StageStatus::Running
                                    ? $currentStage->events->where('type', 'tool_call')->values()
                                    : collect();
                                $stageSlug = $currentStage?->name->value ?? 'preflight';
                                $stageAccent = match ($stageSlug) {
                                    'implement' => 'stage-implement',
                                    'verify' => 'stage-verify',
                                    'release' => 'stage-release',
                                    default => 'stage-preflight',
                                };
                            @endphp
                            <div id="live-tool-calls-panel" class="{{ $currentStage?->status === \App\Enums\StageStatus::Running ? '' : 'hidden' }}">
                                <details open>
                                    <summary class="cursor-pointer text-sm font-semibold text-on-surface-variant">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-{{ $stageAccent }} animate-pulse"></span>
                                            Live Agent Activity
                                        </span>
                                    </summary>
                                    <div id="live-tool-calls" class="mt-2 rounded-xl bg-surface-container-lowest p-3 font-label text-xs space-y-1 max-h-80 overflow-y-auto" data-last-id="{{ $currentStageToolCalls->last()->id ?? 0 }}">
                                        @forelse ($currentStageToolCalls as $tc)
                                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5" data-tool-call-id="{{ $tc->id }}">
                                                <span class="text-outline">{{ $tc->created_at->format('H:i:s') }}</span>
                                                <span class="text-outline">EXEC:</span>
                                                <span class="text-{{ $stageAccent }}">{{ $tc->payload['tool'] ?? '?' }}</span>
                                                @if (! empty($tc->payload['path']))
                                                    <span class="text-outline">PATH:</span>
                                                    <span class="text-on-surface">{{ $tc->payload['path'] }}</span>
                                                @endif
                                                @if (! empty($tc->payload['count']))
                                                    <span class="text-outline">COUNT:</span>
                                                    <span class="text-on-surface">{{ $tc->payload['count'] }}</span>
                                                @endif
                                                @if (! empty($tc->payload['mode']))
                                                    <span class="text-outline">MODE:</span>
                                                    <span class="text-on-surface">{{ $tc->payload['mode'] }}</span>
                                                @endif
                                            </div>
                                        @empty
                                            <p class="text-on-surface-variant" data-empty>Waiting for agent activity…</p>
                                        @endforelse
                                    </div>
                                </details>
                            </div>

                            {{-- Live diff panel (Implement stage) --}}
                            <div id="live-diff-panel" class="{{ $currentStage?->name === \App\Enums\StageName::Implement && $currentStage?->status === \App\Enums\StageStatus::Running ? '' : 'hidden' }}">
                                <details open>
                                    <summary class="cursor-pointer text-sm font-semibold text-on-surface-variant">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-stage-implement animate-pulse"></span>
                                            Live Implementation
                                        </span>
                                    </summary>
                                    <div class="mt-2 space-y-2">
                                        <div id="live-diff-files" class="bg-surface-container rounded-xl p-3">
                                            <p class="text-xs text-on-surface-variant">Waiting for changes…</p>
                                        </div>
                                        <pre id="live-diff-content" class="bg-surface-container-highest text-on-surface rounded-xl p-3 text-xs font-mono overflow-x-auto max-h-96 hidden"></pre>
                                    </div>
                                </details>
                            </div>

                            {{-- Completed implementation (static) --}}
                            @php
                                $diffEvents = $latestRun->stages
                                    ->where('name', \App\Enums\StageName::Implement)
                                    ->flatMap->events
                                    ->where('type', 'implement_complete');
                                $filesChanged = $diffEvents->pluck('payload.files_changed')->flatten()->filter()->unique()->values();
                                $implementSummary = $diffEvents->pluck('payload.summary')->filter()->last();
                            @endphp
                            <div id="static-impl-panel" class="{{ $filesChanged->isNotEmpty() || $implementSummary ? '' : 'hidden' }}">
                                <details open>
                                    <summary class="cursor-pointer text-sm font-semibold text-on-surface-variant">Implementation</summary>
                                    <div class="mt-2 space-y-2">
                                        @if ($implementSummary)
                                            <p class="text-xs text-on-surface-variant">{{ $implementSummary }}</p>
                                        @endif
                                        @if ($filesChanged->isNotEmpty())
                                            <div class="bg-surface-container rounded-xl p-3">
                                                <p class="text-xs font-medium text-on-surface-variant mb-1">Files changed ({{ $filesChanged->count() }})</p>
                                                <ul class="text-xs text-on-surface-variant font-mono space-y-0.5">
                                                    @foreach ($filesChanged as $file)
                                                        <li>{{ $file }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    </div>
                                </details>
                            </div>

                            {{-- Live test output panel (Verify stage) --}}
                            <div id="live-test-panel" class="{{ $currentStage?->name === \App\Enums\StageName::Verify && $currentStage?->status === \App\Enums\StageStatus::Running ? '' : 'hidden' }}">
                                <details open>
                                    <summary class="cursor-pointer text-sm font-semibold text-on-surface-variant">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-stage-verify animate-pulse"></span>
                                            Live Test Output
                                        </span>
                                    </summary>
                                    <pre id="live-test-output" class="mt-2 bg-surface-container-highest text-on-surface rounded-xl p-3 text-xs font-mono overflow-x-auto max-h-96">Waiting for test output…</pre>
                                </details>
                            </div>

                            {{-- Completed test results (static) --}}
                            @php
                                $verifyEvents = $latestRun->stages
                                    ->where('name', \App\Enums\StageName::Verify)
                                    ->flatMap->events
                                    ->where('type', 'verify_complete');
                                $lastVerify = $verifyEvents->last();
                            @endphp
                            <div id="static-test-panel" class="{{ $lastVerify ? '' : 'hidden' }}">
                                <details open>
                                    <summary class="cursor-pointer text-sm font-semibold text-on-surface-variant">
                                        Test Results
                                        <span id="static-test-badge">
                                            @if ($lastVerify)
                                                @if ($lastVerify->payload['passed'] ?? false)
                                                    <span class="ml-1 text-xs text-secondary">Passed</span>
                                                @else
                                                    <span class="ml-1 text-xs text-error">Failed</span>
                                                @endif
                                            @endif
                                        </span>
                                    </summary>
                                    <div id="static-test-content" class="mt-2 bg-surface-container rounded-xl p-3">
                                        @if ($lastVerify)
                                            @if (!empty($lastVerify->payload['summary']))
                                                <p class="text-xs text-on-surface-variant mb-2">{{ $lastVerify->payload['summary'] }}</p>
                                            @endif
                                            @if (!empty($lastVerify->payload['failures']))
                                                <div class="space-y-1">
                                                    @foreach ($lastVerify->payload['failures'] as $failure)
                                                        <div class="text-xs text-error font-mono">
                                                            @if (is_array($failure))
                                                                {{ $failure['test'] ?? '' }}: {{ $failure['assertion'] ?? '' }}
                                                                @if (!empty($failure['file']))
                                                                    <span class="text-on-surface-variant">({{ $failure['file'] }}{{ !empty($failure['line']) ? ':' . $failure['line'] : '' }})</span>
                                                                @endif
                                                            @else
                                                                {{ $failure }}
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        @endif
                                    </div>
                                </details>
                            </div>

                            {{-- Live release progress panel --}}
                            <div id="live-release-panel" class="{{ $currentStage?->name === \App\Enums\StageName::Release && $currentStage?->status === \App\Enums\StageStatus::Running ? '' : 'hidden' }}">
                                <details open>
                                    <summary class="cursor-pointer text-sm font-semibold text-on-surface-variant">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-stage-release animate-pulse"></span>
                                            Release Progress
                                        </span>
                                    </summary>
                                    <div id="live-release-steps" class="mt-2 space-y-2">
                                        <p class="text-xs text-on-surface-variant">Preparing release…</p>
                                    </div>
                                </details>
                            </div>

                            {{-- Release info (static, PR link) --}}
                            @php
                                $prUrl = $latestRun->stages
                                    ->where('name', \App\Enums\StageName::Release)
                                    ->flatMap->events
                                    ->where('type', 'release_complete')
                                    ->pluck('payload.pr_url')
                                    ->filter()
                                    ->last();
                            @endphp
                            <div id="static-release-panel" class="{{ $prUrl ? '' : 'hidden' }}">
                                <div class="rounded-xl bg-secondary-container/30 p-3">
                                    <a id="pr-link" href="{{ $prUrl ?? '#' }}" target="_blank" rel="noopener" class="text-sm font-medium text-secondary hover:underline">
                                        {{ $prUrl ? "Pull Request: $prUrl →" : '' }}
                                    </a>
                                </div>
                            </div>

                        @else
                            <p class="text-sm text-on-surface-variant">No runs yet for this issue.</p>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Right panel: Approval / actions (first on mobile for focus) --}}
            <div class="order-1 lg:order-none lg:col-span-3 rounded-xl bg-surface-container-low overflow-hidden flex flex-col">
                <div class="px-3 py-2 border-b border-outline-variant/40 bg-surface-container">
                    <h2 class="text-sm font-headline font-semibold text-on-surface-variant">Actions</h2>
                </div>

                @if ($activeIssue)
                    <div class="p-4 flex-1 overflow-y-auto">
                        @if ($currentStage && $currentStage->status === \App\Enums\StageStatus::AwaitingApproval)
                            {{-- Awaiting approval --}}
                            <div class="mb-4">
                                <span class="inline-flex items-center rounded-full bg-stage-stuck/20 text-stage-stuck px-2.5 py-0.5 font-label text-[10px] uppercase tracking-widest">
                                    Awaiting Approval
                                </span>
                                <p class="mt-2 text-sm text-on-surface-variant">
                                    <span class="font-medium">{{ ucfirst($currentStage->name->value) }}</span> stage is waiting for your review.
                                </p>
                            </div>

                            <div class="space-y-2">
                                <form method="POST" action="{{ route('issues.approve', $currentStage) }}">
                                    @csrf
                                    <button type="submit"
                                            class="w-full rounded-md bg-secondary px-4 py-2 text-sm font-medium text-on-secondary hover:bg-secondary/90 transition-colors">
                                        Approve (A)
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('issues.reject-stage', $currentStage) }}"
                                      onsubmit="return confirm('Reject this stage?')">
                                    @csrf
                                    <button type="submit"
                                            class="w-full rounded-md bg-error px-4 py-2 text-sm font-medium text-on-error hover:bg-error/90 transition-colors">
                                        Reject (R)
                                    </button>
                                </form>
                            </div>

                        @elseif ($latestRun && $latestRun->status === \App\Enums\RunStatus::Stuck)
                            {{-- Stuck state --}}
                            <div class="mb-4">
                                <span class="inline-flex items-center rounded-full bg-stage-stuck px-2.5 py-0.5 font-label text-[10px] uppercase tracking-widest text-on-background">
                                    {{ str_replace('_', ' ', ucfirst($latestRun->stuck_state?->value ?? 'stuck')) }}
                                </span>
                                @if ($latestRun->iteration > 0)
                                    <span class="ml-2 text-xs text-on-surface-variant">↺ {{ $latestRun->iteration }} iterations</span>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('issues.guidance', $latestRun) }}">
                                @csrf
                                <div class="mb-3">
                                    <label for="guidance" class="block text-xs font-medium text-on-surface-variant mb-1">
                                        Give guidance for retry
                                    </label>
                                    <textarea name="guidance" id="guidance" rows="4" required
                                              class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-xs focus:ring-primary focus:border-primary"
                                              placeholder="Describe what the agent should do differently..."></textarea>
                                    @error('guidance')
                                        <p class="mt-1 text-xs text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                                <button type="submit"
                                        class="w-full rounded-md bg-stage-stuck px-4 py-2 text-sm font-medium text-on-background hover:bg-stage-stuck/90 transition-colors">
                                    Submit Guidance
                                </button>
                            </form>

                        @elseif ($currentStage && $currentStage->status === \App\Enums\StageStatus::Running)
                            {{-- Running --}}
                            <div class="text-center py-6">
                                <div class="inline-flex items-center gap-2">
                                    <span class="inline-block w-2 h-2 rounded-full bg-stage-implement animate-pulse"></span>
                                    <span class="text-sm font-medium text-on-surface-variant">
                                        {{ ucfirst($currentStage->name->value) }} running…
                                    </span>
                                </div>
                            </div>

                        @elseif ($latestRun && $latestRun->status === \App\Enums\RunStatus::Completed)
                            {{-- Completed --}}
                            <div class="text-center py-6">
                                <span class="inline-flex items-center rounded-full bg-secondary-container/30 text-secondary px-3 py-1 text-sm font-medium">
                                    Completed
                                </span>
                            </div>

                        @elseif ($latestRun && $latestRun->status === \App\Enums\RunStatus::Failed)
                            {{-- Failed --}}
                            <div class="text-center py-6">
                                <span class="inline-flex items-center rounded-full bg-error-container/30 text-error px-3 py-1 text-sm font-medium">
                                    Failed
                                </span>
                            </div>

                        @else
                            {{-- No actionable state --}}
                            <div class="text-center py-6 text-sm text-on-surface-variant">
                                No actions available.
                            </div>
                        @endif

                        {{-- Keyboard shortcut hint --}}
                        <div class="mt-6 pt-4 border-t border-outline-variant/40">
                            <p class="text-xs text-outline font-label uppercase tracking-widest">Keyboard shortcuts</p>
                            <div class="mt-1 grid grid-cols-2 gap-1 text-xs text-on-surface-variant">
                                <span><kbd class="px-1 py-0.5 bg-surface-container-high rounded text-xs">A</kbd> Approve</span>
                                <span><kbd class="px-1 py-0.5 bg-surface-container-high rounded text-xs">R</kbd> Reject</span>
                                <span><kbd class="px-1 py-0.5 bg-surface-container-high rounded text-xs">J</kbd> Next</span>
                                <span><kbd class="px-1 py-0.5 bg-surface-container-high rounded text-xs">K</kbd> Previous</span>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const issueLinks = Array.from(document.querySelectorAll('#issue-list a[data-issue-id]'));
                const currentId = '{{ $activeIssue?->id ?? '' }}';
                const currentIndex = issueLinks.findIndex(el => el.dataset.issueId === currentId);

                document.addEventListener('keydown', function (e) {
                    if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;

                    switch (e.key.toLowerCase()) {
                        case 'a': {
                            const approveBtn = document.querySelector('form[action*="approve"] button');
                            if (approveBtn) { e.preventDefault(); approveBtn.closest('form').submit(); }
                            break;
                        }
                        case 'r': {
                            const rejectBtn = document.querySelector('form[action*="reject-stage"] button');
                            if (rejectBtn && confirm('Reject this stage?')) { e.preventDefault(); rejectBtn.closest('form').submit(); }
                            break;
                        }
                        case 'j': {
                            if (currentIndex < issueLinks.length - 1) {
                                e.preventDefault();
                                issueLinks[currentIndex + 1].click();
                            }
                            break;
                        }
                        case 'k': {
                            if (currentIndex > 0) {
                                e.preventDefault();
                                issueLinks[currentIndex - 1].click();
                            }
                            break;
                        }
                    }
                });

                @if ($latestRun)
                // Live progress polling
                (function () {
                    const runId = {{ $latestRun->id }};
                    const progressUrl = '/runs/' + runId + '/progress';
                    let pollInterval = null;
                    let lastData = null;
                    const POLL_ACTIVE = 2000;
                    const POLL_IDLE = 10000;

                    const stageColors = {
                        preflight: { bg: 'bg-stage-preflight', ring: 'ring-stage-preflight', text: 'text-stage-preflight' },
                        implement: { bg: 'bg-stage-implement', ring: 'ring-stage-implement', text: 'text-stage-implement' },
                        verify: { bg: 'bg-stage-verify', ring: 'ring-stage-verify', text: 'text-stage-verify' },
                        release: { bg: 'bg-stage-release', ring: 'ring-stage-release', text: 'text-stage-release' },
                    };

                    function isRunActive(status) {
                        return status === 'running' || status === 'pending';
                    }

                    function updateStagePipeline(stages) {
                        const pipeline = document.getElementById('stage-pipeline');
                        if (!pipeline) return;

                        const stageOrder = ['preflight', 'implement', 'verify', 'release'];
                        const stageMap = {};
                        stages.forEach(s => { stageMap[s.name] = s; });
                        const currentStage = stages.length > 0 ? stages[stages.length - 1] : null;

                        stageOrder.forEach(name => {
                            const el = pipeline.querySelector('[data-stage="' + name + '"]');
                            if (!el) return;
                            const stage = stageMap[name];
                            const dot = el.querySelector('span:first-child');
                            const label = el.querySelector('span:last-child');
                            const colors = stageColors[name];
                            const isCurrent = currentStage && currentStage.name === name;

                            el.className = 'flex items-center gap-1.5' + (isCurrent ? ' font-semibold' : '');

                            if (!stage || stage.status === 'pending') {
                                dot.className = 'flex-shrink-0 w-5 h-5 rounded-full bg-surface-container-high border-2 border-outline-variant';
                                dot.innerHTML = '';
                            } else if (stage.status === 'completed') {
                                dot.className = 'flex-shrink-0 w-5 h-5 rounded-full ' + colors.bg + ' flex items-center justify-center';
                                dot.innerHTML = '<svg class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>';
                            } else if (stage.status === 'running') {
                                dot.className = 'flex-shrink-0 w-5 h-5 rounded-full ' + colors.bg + ' animate-pulse ring-2 ' + colors.ring + ' ring-offset-1 ring-offset-surface-container-low';
                                dot.innerHTML = '';
                            } else if (stage.status === 'awaiting_approval') {
                                dot.className = 'flex-shrink-0 w-5 h-5 rounded-full bg-stage-stuck ring-2 ring-stage-stuck ring-offset-1 ring-offset-surface-container-low';
                                dot.innerHTML = '';
                            } else if (['failed', 'stuck', 'bounced'].includes(stage.status)) {
                                dot.className = 'flex-shrink-0 w-5 h-5 rounded-full bg-error flex items-center justify-center';
                                dot.innerHTML = '<svg class="w-3 h-3 text-on-error" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>';
                            }

                            label.className = 'text-xs ' + (isCurrent ? colors.text : 'text-on-surface-variant');
                        });
                    }

                    const toolAccentByStage = {
                        preflight: 'text-stage-preflight',
                        implement: 'text-stage-implement',
                        verify: 'text-stage-verify',
                        release: 'text-stage-release',
                    };

                    function updateLiveToolCalls(live) {
                        const panel = document.getElementById('live-tool-calls-panel');
                        const list = document.getElementById('live-tool-calls');
                        if (!panel || !list) return;

                        if (live.current_status !== 'running') {
                            panel.classList.add('hidden');
                            return;
                        }
                        panel.classList.remove('hidden');

                        const calls = live.tool_calls || [];
                        if (calls.length === 0) return;

                        const lastSeen = parseInt(list.dataset.lastId || '0', 10);
                        const accent = toolAccentByStage[live.current_stage] || 'text-on-surface';
                        const newCalls = calls.filter(c => c.id > lastSeen);
                        if (newCalls.length === 0) return;

                        const emptyEl = list.querySelector('[data-empty]');
                        if (emptyEl) emptyEl.remove();

                        newCalls.forEach(c => {
                            const row = document.createElement('div');
                            row.className = 'flex flex-wrap items-baseline gap-x-3 gap-y-0.5';
                            row.dataset.toolCallId = c.id;
                            const parts = [
                                '<span class="text-outline">' + escapeHtml(c.timestamp) + '</span>',
                                '<span class="text-outline">EXEC:</span>',
                                '<span class="' + accent + '">' + escapeHtml(c.tool || '?') + '</span>',
                            ];
                            if (c.path) parts.push('<span class="text-outline">PATH:</span><span class="text-on-surface">' + escapeHtml(c.path) + '</span>');
                            if (c.count) parts.push('<span class="text-outline">COUNT:</span><span class="text-on-surface">' + escapeHtml(String(c.count)) + '</span>');
                            if (c.mode) parts.push('<span class="text-outline">MODE:</span><span class="text-on-surface">' + escapeHtml(c.mode) + '</span>');
                            row.innerHTML = parts.join(' ');
                            list.appendChild(row);
                        });

                        list.dataset.lastId = String(newCalls[newCalls.length - 1].id);
                        list.scrollTop = list.scrollHeight;
                    }

                    function updateLiveContent(data) {
                        const live = data.live;
                        updateLiveToolCalls(live);
                        const liveDiff = document.getElementById('live-diff-panel');
                        const staticImpl = document.getElementById('static-impl-panel');
                        const liveTest = document.getElementById('live-test-panel');
                        const staticTest = document.getElementById('static-test-panel');
                        const liveRelease = document.getElementById('live-release-panel');
                        const staticRelease = document.getElementById('static-release-panel');

                        // Implement stage — live diff
                        if (live.current_stage === 'implement' && live.current_status === 'running') {
                            if (liveDiff) liveDiff.classList.remove('hidden');
                            if (staticImpl) staticImpl.classList.add('hidden');

                            if (live.changed_files && live.changed_files.length > 0) {
                                const filesEl = document.getElementById('live-diff-files');
                                if (filesEl) {
                                    filesEl.innerHTML = '<p class="text-xs font-medium text-on-surface-variant mb-1">Files changed (' + live.changed_files.length + ')</p>'
                                        + '<ul class="text-xs text-on-surface-variant font-mono space-y-0.5">'
                                        + live.changed_files.map(f => '<li>' + escapeHtml(f) + '</li>').join('')
                                        + '</ul>';
                                }
                            }
                            if (live.diff) {
                                const diffEl = document.getElementById('live-diff-content');
                                if (diffEl) {
                                    diffEl.classList.remove('hidden');
                                    diffEl.textContent = live.diff;
                                }
                            }
                        } else {
                            if (liveDiff) liveDiff.classList.add('hidden');
                        }

                        // Verify stage — live test output
                        if (live.current_stage === 'verify' && live.current_status === 'running') {
                            if (liveTest) liveTest.classList.remove('hidden');
                            if (staticTest) staticTest.classList.add('hidden');

                            if (live.test_output) {
                                const testEl = document.getElementById('live-test-output');
                                if (testEl) {
                                    testEl.textContent = live.test_output;
                                    testEl.scrollTop = testEl.scrollHeight;
                                }
                            }
                        } else {
                            if (liveTest) liveTest.classList.add('hidden');
                            if (live.test_output && live.current_stage !== 'verify') {
                                if (staticTest) {
                                    staticTest.classList.remove('hidden');
                                    const badge = document.getElementById('static-test-badge');
                                    if (badge) {
                                        const passed = live.test_status === 'passed';
                                        badge.innerHTML = passed
                                            ? '<span class="ml-1 text-xs text-secondary">Passed</span>'
                                            : '<span class="ml-1 text-xs text-error">Failed</span>';
                                    }
                                    const content = document.getElementById('static-test-content');
                                    if (content && live.test_output) {
                                        content.innerHTML = '<p class="text-xs text-on-surface-variant">' + escapeHtml(live.test_output) + '</p>';
                                    }
                                }
                            }
                        }

                        // Release stage — live progress
                        if (live.current_stage === 'release' && live.current_status === 'running') {
                            if (liveRelease) liveRelease.classList.remove('hidden');
                            if (staticRelease) staticRelease.classList.add('hidden');

                            if (live.release_steps && live.release_steps.length > 0) {
                                const stepsEl = document.getElementById('live-release-steps');
                                if (stepsEl) {
                                    stepsEl.innerHTML = live.release_steps.map(function(s) {
                                        const icon = s.step === 'pr_created'
                                            ? '<svg class="w-3.5 h-3.5 text-secondary flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>'
                                            : '<span class="w-3.5 h-3.5 flex-shrink-0 flex items-center justify-center"><span class="w-1.5 h-1.5 rounded-full bg-secondary"></span></span>';
                                        let detail = escapeHtml(s.detail);
                                        if (s.pr_url) {
                                            detail = '<a href="' + escapeHtml(s.pr_url) + '" target="_blank" class="text-secondary hover:underline">' + escapeHtml(s.pr_url) + '</a>';
                                        }
                                        return '<div class="flex items-start gap-2 text-xs">'
                                            + icon
                                            + '<div><span class="font-medium text-on-surface">' + escapeHtml(s.step.replace(/_/g, ' ')) + '</span>'
                                            + '<span class="ml-1 text-on-surface-variant">' + detail + '</span></div></div>';
                                    }).join('');
                                }
                            }
                        } else {
                            if (liveRelease) liveRelease.classList.add('hidden');
                        }

                        // Show PR link when available
                        if (live.pr_url) {
                            if (staticRelease) {
                                staticRelease.classList.remove('hidden');
                                const prLink = document.getElementById('pr-link');
                                if (prLink) {
                                    prLink.href = live.pr_url;
                                    prLink.textContent = 'Pull Request: ' + live.pr_url + ' →';
                                }
                            }
                        }

                        // Refresh page when run transitions to a terminal or awaiting state
                        if (data.run_status === 'stuck' || data.run_status === 'completed' || data.run_status === 'failed') {
                            if (lastData && lastData.run_status === 'running') {
                                window.location.reload();
                            }
                        }
                        if (live.current_status === 'awaiting_approval') {
                            if (lastData && lastData.live && lastData.live.current_status !== 'awaiting_approval') {
                                window.location.reload();
                            }
                        }
                    }

                    function escapeHtml(str) {
                        if (!str) return '';
                        const div = document.createElement('div');
                        div.textContent = str;
                        return div.innerHTML;
                    }

                    function poll() {
                        fetch(progressUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(function(r) { return r.ok ? r.json() : null; })
                            .then(function(data) {
                                if (!data) return;

                                updateStagePipeline(data.stages);
                                updateLiveContent(data);

                                // Adjust poll rate
                                const active = isRunActive(data.run_status);
                                const newInterval = active ? POLL_ACTIVE : POLL_IDLE;
                                if (pollInterval && pollInterval._interval !== newInterval) {
                                    clearInterval(pollInterval);
                                    pollInterval = setInterval(poll, newInterval);
                                    pollInterval._interval = newInterval;
                                }

                                lastData = data;
                            })
                            .catch(function() {});
                    }

                    // Start polling — reconnect on visibility change (handles app sleep)
                    const runStatus = '{{ $latestRun->status->value }}';
                    const initialInterval = (runStatus === 'running' || runStatus === 'pending') ? POLL_ACTIVE : POLL_IDLE;
                    pollInterval = setInterval(poll, initialInterval);
                    pollInterval._interval = initialInterval;
                    poll();

                    document.addEventListener('visibilitychange', function () {
                        if (document.visibilityState === 'visible') {
                            poll();
                            if (!pollInterval) {
                                pollInterval = setInterval(poll, POLL_ACTIVE);
                                pollInterval._interval = POLL_ACTIVE;
                            }
                        } else {
                            if (pollInterval) {
                                clearInterval(pollInterval);
                                pollInterval = null;
                            }
                        }
                    });
                })();
                @endif
            });
        </script>
    @endif
@endsection
