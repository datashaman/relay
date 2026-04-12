@extends('layouts.app')

@section('title', 'Stuck Issues')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Stuck Issues</h1>
    </div>

    @if ($stuckRuns->isEmpty())
        <div class="mt-8 text-center text-gray-500 dark:text-gray-400">
            <p class="text-lg">No stuck issues.</p>
            <p class="text-sm mt-1">Issues that get stuck during processing will appear here.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($stuckRuns as $run)
                @php
                    $stuckMeta = match ($run->stuck_state) {
                        \App\Enums\StuckState::IterationCap => [
                            'label' => 'Iteration Cap',
                            'description' => 'Agent exceeded the maximum number of retry iterations.',
                            'action' => 'Give Guidance',
                            'action_route' => route('stuck.guidance', $run),
                            'action_method' => 'GET',
                            'color' => 'amber',
                        ],
                        \App\Enums\StuckState::Timeout => [
                            'label' => 'Timeout',
                            'description' => 'Stage timed out with no state changes.',
                            'action' => 'Restart',
                            'action_route' => route('stuck.restart', $run),
                            'action_method' => 'POST',
                            'color' => 'red',
                        ],
                        \App\Enums\StuckState::AgentUncertain => [
                            'label' => 'Agent Uncertain',
                            'description' => 'Agent has low confidence or conflicting requirements.',
                            'action' => 'Give Guidance',
                            'action_route' => route('stuck.guidance', $run),
                            'action_method' => 'GET',
                            'color' => 'yellow',
                        ],
                        \App\Enums\StuckState::ExternalBlocker => [
                            'label' => 'External Blocker',
                            'description' => 'Missing credentials, environment setup, or external dependency.',
                            'action' => 'Restart',
                            'action_route' => route('stuck.restart', $run),
                            'action_method' => 'POST',
                            'color' => 'orange',
                        ],
                        default => [
                            'label' => 'Unknown',
                            'description' => 'Unknown stuck state.',
                            'action' => 'Restart',
                            'action_route' => route('stuck.restart', $run),
                            'action_method' => 'POST',
                            'color' => 'gray',
                        ],
                    };
                    $latestStage = $run->stages->first();
                @endphp
                <div class="rounded-lg border border-{{ $stuckMeta['color'] }}-300 dark:border-{{ $stuckMeta['color'] }}-700 bg-{{ $stuckMeta['color'] }}-50 dark:bg-{{ $stuckMeta['color'] }}-900/20 p-4">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="inline-flex items-center rounded-full bg-amber-500 px-2 py-0.5 text-xs font-bold text-white">
                                    {{ $stuckMeta['label'] }}
                                </span>
                                @if ($run->stuck_unread)
                                    <span class="inline-block w-2 h-2 rounded-full bg-red-500"></span>
                                @endif
                                @if ($run->iteration > 0)
                                    <span class="text-xs text-gray-500 dark:text-gray-400">&circlearrowright; {{ $run->iteration }}</span>
                                @endif
                            </div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {{ $run->issue->title ?? 'Unknown Issue' }}
                            </h3>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
                                {{ $stuckMeta['description'] }}
                            </p>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                @if ($latestStage)
                                    Stage: {{ $latestStage->name->value }}
                                @endif
                                &middot; Source: {{ $run->issue->source->external_account ?? $run->issue->source->name ?? 'N/A' }}
                                &middot; Updated {{ $run->updated_at->diffForHumans() }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2 sm:ml-4">
                            @if ($stuckMeta['action_method'] === 'GET')
                                <a href="{{ $stuckMeta['action_route'] }}"
                                   class="rounded-md bg-amber-600 px-4 py-2.5 sm:px-3 sm:py-1.5 text-sm sm:text-xs font-medium text-white hover:bg-amber-500 active:bg-amber-700">
                                    {{ $stuckMeta['action'] }}
                                </a>
                            @else
                                <form method="POST" action="{{ $stuckMeta['action_route'] }}">
                                    @csrf
                                    <button type="submit"
                                            class="rounded-md bg-amber-600 px-4 py-2.5 sm:px-3 sm:py-1.5 text-sm sm:text-xs font-medium text-white hover:bg-amber-500 active:bg-amber-700">
                                        {{ $stuckMeta['action'] }}
                                    </button>
                                </form>
                            @endif
                            @if (in_array($run->stuck_state, [\App\Enums\StuckState::IterationCap, \App\Enums\StuckState::AgentUncertain]))
                                <form method="POST" action="{{ route('stuck.restart', $run) }}">
                                    @csrf
                                    <button type="submit"
                                            class="rounded-md bg-gray-600 px-4 py-2.5 sm:px-3 sm:py-1.5 text-sm sm:text-xs font-medium text-white hover:bg-gray-500 active:bg-gray-700">
                                        Restart
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
