@extends('layouts.app')

@section('title', 'Overview')

@section('content')
@php
    $intakeActive = $intakePausedCount === 0;
    $stageMeta = [
        'preflight' => ['color' => 'stage-preflight', 'icon' => 'move_to_inbox', 'label' => 'Preflight'],
        'implement' => ['color' => 'stage-implement', 'icon' => 'build', 'label' => 'Implement'],
        'verify'    => ['color' => 'stage-verify',    'icon' => 'fact_check', 'label' => 'Verify'],
        'release'   => ['color' => 'stage-release',   'icon' => 'rocket_launch', 'label' => 'Release'],
    ];
@endphp

<div class="space-y-6">
    {{-- Intake status strip --}}
    <section class="flex items-center justify-between bg-surface-container-low p-4 rounded-xl">
        <div class="flex flex-col">
            <span class="font-label text-[10px] text-outline uppercase tracking-widest">Intake Status</span>
            <span class="font-label text-xs uppercase tracking-widest {{ $intakeActive ? 'text-secondary' : 'text-stage-stuck' }}">
                {{ $intakeActive ? 'Active System' : $intakePausedCount . ' / ' . $sourceCount . ' sources paused' }}
            </span>
        </div>
        <a href="{{ route('intake.index') }}" class="font-label text-[10px] text-primary uppercase tracking-widest hover:underline">
            Manage Sources →
        </a>
    </section>

    {{-- Bento stats --}}
    <section class="grid grid-cols-2 md:grid-cols-4 gap-3">
        @foreach ($stageMeta as $key => $meta)
            <div class="bg-surface-container-low p-4 rounded-xl border-l-4 border-{{ $meta['color'] }} flex flex-col gap-1">
                <span class="font-headline text-3xl leading-none text-on-surface">{{ str_pad((string) $activeStageCounts[$key], 2, '0', STR_PAD_LEFT) }}</span>
                <span class="font-label text-[10px] text-outline uppercase tracking-widest">{{ $meta['label'] }}</span>
            </div>
        @endforeach
    </section>

    {{-- Secondary totals --}}
    <section class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-surface-container-low p-4 rounded-xl flex flex-col gap-1">
            <span class="font-headline text-xl text-on-surface">{{ $totals['queued'] }}</span>
            <span class="font-label text-[10px] text-outline uppercase tracking-widest">In Queue</span>
        </div>
        <div class="bg-surface-container-low p-4 rounded-xl flex flex-col gap-1 border-l-4 border-stage-stuck">
            <span class="font-headline text-xl text-stage-stuck">{{ $totals['stuck'] }}</span>
            <span class="font-label text-[10px] text-outline uppercase tracking-widest">Stuck</span>
        </div>
        <div class="bg-surface-container-low p-4 rounded-xl flex flex-col gap-1">
            <span class="font-headline text-xl text-secondary">{{ $totals['shipped_week'] }}</span>
            <span class="font-label text-[10px] text-outline uppercase tracking-widest">Shipped / Week</span>
        </div>
        <div class="bg-surface-container-low p-4 rounded-xl flex flex-col gap-1">
            <span class="font-headline text-xl text-on-surface">{{ $totals['active'] }}</span>
            <span class="font-label text-[10px] text-outline uppercase tracking-widest">Active Runs</span>
        </div>
    </section>

    {{-- Active pipeline list --}}
    <section class="space-y-3">
        <div class="flex items-center justify-between px-1">
            <h2 class="font-headline text-xl text-on-surface">Active Issues</h2>
            <span class="font-label text-[10px] text-outline uppercase tracking-widest">
                {{ $totals['active'] }} {{ Str::plural('issue', $totals['active']) }}
            </span>
        </div>

        @if ($activeRuns->isEmpty())
            <div class="bg-surface-container-low rounded-xl p-8 text-center">
                <p class="text-on-surface-variant">No active runs.</p>
                <a href="{{ route('intake.index') }}" class="mt-2 inline-block font-label text-[10px] text-primary uppercase tracking-widest hover:underline">
                    Accept from Queue →
                </a>
            </div>
        @else
            @foreach ($activeRuns as $run)
                @php
                    $issue = $run->issue;
                    $currentStage = $run->stages->first();
                    $stageKey = $currentStage?->name->value ?? 'preflight';
                    $stageInfo = $stageMeta[$stageKey] ?? $stageMeta['preflight'];
                    $isStuck = $run->status === \App\Enums\RunStatus::Stuck;
                    $cardClass = $isStuck
                        ? 'bg-surface-container-high border-l-4 border-stage-stuck shadow-[0_0_40px_-16px_rgba(239,159,39,0.25)]'
                        : 'bg-surface-container-low hover:bg-surface-container';
                @endphp
                <a href="{{ route('issues.show', $issue) }}"
                   class="block rounded-xl p-4 space-y-3 transition-colors {{ $cardClass }}">
                    <div class="flex justify-between items-start gap-3">
                        <div class="space-y-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-label text-[10px] text-primary uppercase tracking-widest">{{ $issue->source->type->value === 'jira' ? $issue->external_id : 'RLY-' . $issue->external_id }}</span>
                                <span class="w-1 h-1 bg-outline-variant rounded-full"></span>
                                @if ($isStuck)
                                    <span class="font-label text-[10px] text-stage-stuck uppercase tracking-widest animate-pulse">
                                        Stuck &middot; {{ str_replace('_', ' ', $run->stuck_state?->value ?? '') }}
                                    </span>
                                @else
                                    <span class="font-label text-[10px] text-{{ $stageInfo['color'] }} uppercase tracking-widest">
                                        {{ $stageInfo['label'] }} {{ $currentStage?->status->value === 'awaiting_approval' ? '· approval' : '' }}
                                    </span>
                                @endif
                                @if ($run->iteration > 1)
                                    <span class="font-label text-[10px] text-on-surface-variant">↺ {{ $run->iteration }}</span>
                                @endif
                            </div>
                            <h3 class="font-body text-sm font-semibold leading-tight text-on-surface truncate">{{ $issue->title }}</h3>
                        </div>
                        @if ($isStuck)
                            <span class="font-label text-[10px] text-error uppercase tracking-widest shrink-0">Needs You</span>
                        @endif
                    </div>

                    <div class="bg-surface-container-lowest px-3 py-2 rounded-md space-y-1.5">
                        <div class="flex items-center gap-3">
                            <span class="font-label text-[10px] text-outline">STAGE:</span>
                            <span class="font-label text-[10px] text-{{ $stageInfo['color'] }} uppercase tracking-widest">
                                {{ $stageInfo['label'] }} Phase
                            </span>
                            @if ($currentStage)
                                <span class="font-label text-[10px] text-outline ml-auto">
                                    {{ strtoupper($currentStage->status->value) }}
                                </span>
                            @endif
                        </div>
                        @php
                            $stageOrder = ['preflight', 'implement', 'verify', 'release'];
                            $stageIndex = array_search($stageKey, $stageOrder, true);
                            $stageIndex = $stageIndex === false ? 0 : $stageIndex;
                        @endphp
                        <div class="flex gap-1">
                            @foreach ($stageOrder as $idx => $name)
                                @php
                                    $segColor = match (true) {
                                        $idx < $stageIndex => 'bg-' . $stageMeta[$name]['color'],
                                        $idx === $stageIndex && $isStuck => 'bg-stage-stuck',
                                        $idx === $stageIndex => 'bg-' . $stageMeta[$name]['color'] . ' animate-pulse',
                                        default => 'bg-surface-container-high',
                                    };
                                @endphp
                                <div class="h-1 flex-1 rounded-full {{ $segColor }}"></div>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex justify-between items-center">
                        <span class="font-label text-[10px] text-outline uppercase tracking-widest">
                            {{ $issue->source->name }}
                        </span>
                        <span class="font-label text-[10px] text-outline">{{ $run->updated_at->diffForHumans(null, true) }} ago</span>
                    </div>
                </a>
            @endforeach
        @endif
    </section>
</div>
@endsection
