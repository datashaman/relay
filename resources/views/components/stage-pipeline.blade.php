@props(['run'])

@php
    $stageOrder = ['preflight', 'implement', 'verify', 'release'];
    $stageColors = [
        'preflight' => ['bg' => 'bg-stage-preflight', 'text' => 'text-stage-preflight', 'ring' => 'ring-stage-preflight'],
        'implement' => ['bg' => 'bg-stage-implement', 'text' => 'text-stage-implement', 'ring' => 'ring-stage-implement'],
        'verify' => ['bg' => 'bg-stage-verify', 'text' => 'text-stage-verify', 'ring' => 'ring-stage-verify'],
        'release' => ['bg' => 'bg-stage-release', 'text' => 'text-stage-release', 'ring' => 'ring-stage-release'],
    ];

    $stageMap = $run->stages->keyBy(fn ($s) => $s->name->value);
    $currentStage = $run->stages->last();
    $currentStageName = $currentStage?->name->value;
@endphp

<div class="flex items-center gap-1" role="progressbar">
    @foreach ($stageOrder as $i => $name)
        @php
            $stage = $stageMap[$name] ?? null;
            $colors = $stageColors[$name];
            $isCurrent = $currentStageName === $name;
            $isCompleted = $stage && $stage->status->value === 'completed';
            $isRunning = $stage && $stage->status->value === 'running';
            $isFailed = $stage && in_array($stage->status->value, ['failed', 'stuck', 'bounced']);
            $isPending = !$stage || $stage->status->value === 'pending';
            $isAwaiting = $stage && $stage->status->value === 'awaiting_approval';
        @endphp

        @if ($i > 0)
            <div class="flex-shrink-0 w-6 h-px {{ $isCompleted || ($stage && !$isPending) ? 'bg-outline' : 'bg-outline-variant/40' }}"></div>
        @endif

        <div class="flex items-center gap-1.5 {{ $isCurrent ? 'font-semibold' : '' }}" data-stage="{{ $name }}">
            @if ($isCompleted)
                <span class="flex-shrink-0 w-5 h-5 rounded-full {{ $colors['bg'] }} flex items-center justify-center">
                    <svg class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                </span>
            @elseif ($isRunning)
                <span class="flex-shrink-0 w-5 h-5 rounded-full {{ $colors['bg'] }} animate-pulse ring-2 {{ $colors['ring'] }} ring-offset-1 ring-offset-surface-container-low"></span>
            @elseif ($isAwaiting)
                <span class="flex-shrink-0 w-5 h-5 rounded-full bg-stage-stuck ring-2 ring-stage-stuck ring-offset-1 ring-offset-surface-container-low"></span>
            @elseif ($isFailed)
                <span class="flex-shrink-0 w-5 h-5 rounded-full bg-error flex items-center justify-center">
                    <svg class="w-3 h-3 text-on-error" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </span>
            @else
                <span class="flex-shrink-0 w-5 h-5 rounded-full bg-surface-container-high border-2 border-outline-variant"></span>
            @endif

            <span class="text-xs {{ $isCurrent ? $colors['text'] : 'text-on-surface-variant' }}">
                {{ ucfirst($name) }}
            </span>
        </div>
    @endforeach

    @if ($run->iteration > 1)
        <span class="ml-2 text-xs text-primary font-medium">↺ {{ $run->iteration }}</span>
    @endif
</div>
