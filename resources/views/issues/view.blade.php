@extends('layouts.app')

@section('title', $activeIssue ? $activeIssue->title : 'Issues')
@section('container_class', 'max-w-7xl')

@section('content')
    @if ($issues->isEmpty())
        <div class="text-center text-gray-500 dark:text-gray-400 py-16">
            <p class="text-lg">No pipeline issues yet.</p>
            <p class="text-sm mt-1">Accept issues from the <a href="{{ route('issues.queue') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">queue</a> to see them here.</p>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 min-h-[calc(100vh-10rem)]" id="issue-view">
            {{-- Left panel: Issue queue --}}
            <div class="lg:col-span-3 border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 overflow-hidden flex flex-col">
                <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Pipeline Issues</h2>
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
                           class="block px-3 py-2.5 border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors {{ $isActive ? 'bg-indigo-50 dark:bg-indigo-900/20 border-l-2 border-l-indigo-500' : '' }}">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $listIssue->title }}</div>
                            <div class="flex items-center gap-2 mt-1">
                                @php $statusColors = match($listIssue->status->value) {
                                    'accepted' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                                    'in_progress' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                                    'completed' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                                    'failed' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
                                    'stuck' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                    default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                }; @endphp
                                <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium {{ $statusColors }}">
                                    {{ str_replace('_', ' ', ucfirst($listIssue->status->value)) }}
                                </span>
                                @if ($listStage)
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ ucfirst($listStage->name->value) }}</span>
                                @endif
                                @if ($listRun && $listRun->iteration > 1)
                                    <span class="text-xs text-indigo-600 dark:text-indigo-400">↺ {{ $listRun->iteration }}</span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Center panel: Active issue details --}}
            <div class="lg:col-span-6 border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 overflow-hidden flex flex-col">
                @if ($activeIssue)
                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $activeIssue->title }}</h2>
                        <div class="flex items-center gap-2 mt-1 text-xs text-gray-500 dark:text-gray-400">
                            @if ($activeIssue->external_id)
                                <span>{{ $activeIssue->external_id }}</span>
                            @endif
                            @if ($activeIssue->external_url)
                                <a href="{{ $activeIssue->external_url }}" target="_blank" class="text-indigo-600 dark:text-indigo-400 hover:underline">External →</a>
                            @endif
                            @if ($latestRun)
                                <span>·</span>
                                <a href="{{ route('runs.timeline', $latestRun) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">Full Timeline</a>
                            @endif
                        </div>
                    </div>

                    <div class="overflow-y-auto flex-1 p-4 space-y-4">
                        @if ($latestRun)
                            {{-- Preflight doc --}}
                            @if ($latestRun->preflight_doc)
                                <details open>
                                    <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-300">Preflight Doc</summary>
                                    <div class="mt-2 prose prose-sm dark:prose-invert max-w-none bg-gray-50 dark:bg-gray-900 rounded-lg p-3 text-xs whitespace-pre-wrap">{{ $latestRun->preflight_doc }}</div>
                                </details>
                            @endif

                            {{-- Diff from implement stages --}}
                            @php
                                $diffEvents = $latestRun->stages
                                    ->where('name', \App\Enums\StageName::Implement)
                                    ->flatMap->events
                                    ->where('type', 'implement_complete');
                                $filesChanged = $diffEvents->pluck('payload.files_changed')->flatten()->filter()->unique()->values();
                                $implementSummary = $diffEvents->pluck('payload.summary')->filter()->last();
                            @endphp
                            @if ($filesChanged->isNotEmpty() || $implementSummary)
                                <details open>
                                    <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-300">Implementation</summary>
                                    <div class="mt-2 space-y-2">
                                        @if ($implementSummary)
                                            <p class="text-xs text-gray-600 dark:text-gray-400">{{ $implementSummary }}</p>
                                        @endif
                                        @if ($filesChanged->isNotEmpty())
                                            <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
                                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Files changed ({{ $filesChanged->count() }})</p>
                                                <ul class="text-xs text-gray-600 dark:text-gray-400 font-mono space-y-0.5">
                                                    @foreach ($filesChanged as $file)
                                                        <li>{{ $file }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    </div>
                                </details>
                            @endif

                            {{-- Test output from verify stages --}}
                            @php
                                $verifyEvents = $latestRun->stages
                                    ->where('name', \App\Enums\StageName::Verify)
                                    ->flatMap->events
                                    ->where('type', 'verify_complete');
                                $lastVerify = $verifyEvents->last();
                            @endphp
                            @if ($lastVerify)
                                <details open>
                                    <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-300">
                                        Test Results
                                        @if ($lastVerify->payload['passed'] ?? false)
                                            <span class="ml-1 text-xs text-green-600 dark:text-green-400">Passed</span>
                                        @else
                                            <span class="ml-1 text-xs text-red-600 dark:text-red-400">Failed</span>
                                        @endif
                                    </summary>
                                    <div class="mt-2 bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
                                        @if (!empty($lastVerify->payload['summary']))
                                            <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">{{ $lastVerify->payload['summary'] }}</p>
                                        @endif
                                        @if (!empty($lastVerify->payload['failures']))
                                            <div class="space-y-1">
                                                @foreach ($lastVerify->payload['failures'] as $failure)
                                                    <div class="text-xs text-red-600 dark:text-red-400 font-mono">
                                                        @if (is_array($failure))
                                                            {{ $failure['test'] ?? '' }}: {{ $failure['assertion'] ?? '' }}
                                                            @if (!empty($failure['file']))
                                                                <span class="text-gray-500">({{ $failure['file'] }}{{ !empty($failure['line']) ? ':' . $failure['line'] : '' }})</span>
                                                            @endif
                                                        @else
                                                            {{ $failure }}
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </details>
                            @endif

                            {{-- Release info --}}
                            @php
                                $prUrl = $latestRun->stages
                                    ->where('name', \App\Enums\StageName::Release)
                                    ->flatMap->events
                                    ->where('type', 'release_complete')
                                    ->pluck('payload.pr_url')
                                    ->filter()
                                    ->last();
                            @endphp
                            @if ($prUrl)
                                <div class="rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 p-3">
                                    <a href="{{ $prUrl }}" target="_blank" rel="noopener" class="text-sm font-medium text-green-700 dark:text-green-300 hover:underline">
                                        Pull Request: {{ $prUrl }} →
                                    </a>
                                </div>
                            @endif

                        @else
                            <p class="text-sm text-gray-500 dark:text-gray-400">No runs yet for this issue.</p>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Right panel: Approval / actions --}}
            <div class="lg:col-span-3 border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 overflow-hidden flex flex-col">
                <div class="px-3 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Actions</h2>
                </div>

                @if ($activeIssue)
                    <div class="p-4 flex-1 overflow-y-auto">
                        @if ($currentStage && $currentStage->status === \App\Enums\StageStatus::AwaitingApproval)
                            {{-- Awaiting approval --}}
                            <div class="mb-4">
                                <span class="inline-flex items-center rounded-full bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300 px-2.5 py-0.5 text-xs font-medium">
                                    Awaiting Approval
                                </span>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                    <span class="font-medium">{{ ucfirst($currentStage->name->value) }}</span> stage is waiting for your review.
                                </p>
                            </div>

                            <div class="space-y-2">
                                <form method="POST" action="{{ route('issues.approve', $currentStage) }}">
                                    @csrf
                                    <button type="submit"
                                            class="w-full rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 transition-colors">
                                        Approve (A)
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('issues.reject-stage', $currentStage) }}"
                                      onsubmit="return confirm('Reject this stage?')">
                                    @csrf
                                    <button type="submit"
                                            class="w-full rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 transition-colors">
                                        Reject (R)
                                    </button>
                                </form>
                            </div>

                        @elseif ($latestRun && $latestRun->status === \App\Enums\RunStatus::Stuck)
                            {{-- Stuck state --}}
                            <div class="mb-4">
                                <span class="inline-flex items-center rounded-full bg-amber-500 px-2.5 py-0.5 text-xs font-bold text-white">
                                    {{ str_replace('_', ' ', ucfirst($latestRun->stuck_state?->value ?? 'stuck')) }}
                                </span>
                                @if ($latestRun->iteration > 0)
                                    <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">↺ {{ $latestRun->iteration }} iterations</span>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('issues.guidance', $latestRun) }}">
                                @csrf
                                <div class="mb-3">
                                    <label for="guidance" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Give guidance for retry
                                    </label>
                                    <textarea name="guidance" id="guidance" rows="4" required
                                              class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-3 py-2 text-xs focus:ring-indigo-500 focus:border-indigo-500"
                                              placeholder="Describe what the agent should do differently..."></textarea>
                                    @error('guidance')
                                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>
                                <button type="submit"
                                        class="w-full rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-500 transition-colors">
                                    Submit Guidance
                                </button>
                            </form>

                        @elseif ($currentStage && $currentStage->status === \App\Enums\StageStatus::Running)
                            {{-- Running --}}
                            <div class="text-center py-6">
                                <div class="inline-flex items-center gap-2">
                                    <span class="inline-block w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {{ ucfirst($currentStage->name->value) }} running…
                                    </span>
                                </div>
                            </div>

                        @elseif ($latestRun && $latestRun->status === \App\Enums\RunStatus::Completed)
                            {{-- Completed --}}
                            <div class="text-center py-6">
                                <span class="inline-flex items-center rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300 px-3 py-1 text-sm font-medium">
                                    Completed
                                </span>
                            </div>

                        @elseif ($latestRun && $latestRun->status === \App\Enums\RunStatus::Failed)
                            {{-- Failed --}}
                            <div class="text-center py-6">
                                <span class="inline-flex items-center rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 px-3 py-1 text-sm font-medium">
                                    Failed
                                </span>
                            </div>

                        @else
                            {{-- No actionable state --}}
                            <div class="text-center py-6 text-sm text-gray-500 dark:text-gray-400">
                                No actions available.
                            </div>
                        @endif

                        {{-- Keyboard shortcut hint --}}
                        <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <p class="text-xs text-gray-400 dark:text-gray-500">Keyboard shortcuts</p>
                            <div class="mt-1 grid grid-cols-2 gap-1 text-xs text-gray-500 dark:text-gray-400">
                                <span><kbd class="px-1 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-xs">A</kbd> Approve</span>
                                <span><kbd class="px-1 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-xs">R</kbd> Reject</span>
                                <span><kbd class="px-1 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-xs">J</kbd> Next</span>
                                <span><kbd class="px-1 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-xs">K</kbd> Previous</span>
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
            });
        </script>
    @endif
@endsection
