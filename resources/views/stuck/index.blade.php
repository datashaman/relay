@extends('layouts.app')

@section('title', 'Stuck Issues')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-headline font-bold">Stuck Issues</h1>
    </div>

    @if ($stuckRuns->isEmpty())
        <div class="mt-8 text-center text-on-surface-variant">
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
                            'accent' => 'border-l-stage-stuck',
                        ],
                        \App\Enums\StuckState::Timeout => [
                            'label' => 'Timeout',
                            'description' => 'Stage timed out with no state changes.',
                            'action' => 'Restart',
                            'action_route' => route('stuck.restart', $run),
                            'action_method' => 'POST',
                            'accent' => 'border-l-error',
                        ],
                        \App\Enums\StuckState::AgentUncertain => [
                            'label' => 'Agent Uncertain',
                            'description' => 'Agent has low confidence or conflicting requirements.',
                            'action' => 'Give Guidance',
                            'action_route' => route('stuck.guidance', $run),
                            'action_method' => 'GET',
                            'accent' => 'border-l-tertiary',
                        ],
                        \App\Enums\StuckState::ExternalBlocker => [
                            'label' => 'External Blocker',
                            'description' => 'Missing credentials, environment setup, or external dependency.',
                            'action' => 'Restart',
                            'action_route' => route('stuck.restart', $run),
                            'action_method' => 'POST',
                            'accent' => 'border-l-error',
                        ],
                        default => [
                            'label' => 'Unknown',
                            'description' => 'Unknown stuck state.',
                            'action' => 'Restart',
                            'action_route' => route('stuck.restart', $run),
                            'action_method' => 'POST',
                            'accent' => 'border-l-outline',
                        ],
                    };
                    $latestStage = $run->stages->first();
                @endphp
                <div class="rounded-xl bg-surface-container-low border-l-4 {{ $stuckMeta['accent'] }} p-4 shadow-[0_0_40px_-16px_rgba(239,159,39,0.25)]">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="inline-flex items-center rounded-full bg-stage-stuck px-2 py-0.5 font-label text-[10px] uppercase tracking-widest text-on-background">
                                    {{ $stuckMeta['label'] }}
                                </span>
                                @if ($run->stuck_unread)
                                    <span class="inline-block w-2 h-2 rounded-full bg-error"></span>
                                @endif
                                @if ($run->iteration > 0)
                                    <span class="text-xs text-on-surface-variant">&circlearrowright; {{ $run->iteration }}</span>
                                @endif
                            </div>
                            <h3 class="text-sm font-semibold text-on-surface">
                                {{ $run->issue->title ?? 'Unknown Issue' }}
                            </h3>
                            <p class="text-xs text-on-surface-variant mt-0.5">
                                {{ $stuckMeta['description'] }}
                            </p>
                            <div class="text-xs text-on-surface-variant mt-1">
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
                                   class="rounded-md bg-stage-stuck px-4 py-2.5 sm:px-3 sm:py-1.5 text-sm sm:text-xs font-medium text-on-background hover:bg-stage-stuck/90 active:bg-stage-stuck/80">
                                    {{ $stuckMeta['action'] }}
                                </a>
                            @else
                                <form method="POST" action="{{ $stuckMeta['action_route'] }}">
                                    @csrf
                                    <button type="submit"
                                            class="rounded-md bg-stage-stuck px-4 py-2.5 sm:px-3 sm:py-1.5 text-sm sm:text-xs font-medium text-on-background hover:bg-stage-stuck/90 active:bg-stage-stuck/80">
                                        {{ $stuckMeta['action'] }}
                                    </button>
                                </form>
                            @endif
                            @if (in_array($run->stuck_state, [\App\Enums\StuckState::IterationCap, \App\Enums\StuckState::AgentUncertain]))
                                <form method="POST" action="{{ route('stuck.restart', $run) }}">
                                    @csrf
                                    <button type="submit"
                                            class="rounded-md bg-surface-container-high px-4 py-2.5 sm:px-3 sm:py-1.5 text-sm sm:text-xs font-medium text-on-surface hover:bg-surface-container-highest">
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
