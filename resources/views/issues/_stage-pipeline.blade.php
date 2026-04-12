@php
    $stageOrder = ['preflight', 'implement', 'verify', 'release'];
    $stageColors = [
        'preflight' => ['bg' => 'bg-indigo-500', 'text' => 'text-indigo-700 dark:text-indigo-300', 'ring' => 'ring-indigo-500'],
        'implement' => ['bg' => 'bg-blue-500', 'text' => 'text-blue-700 dark:text-blue-300', 'ring' => 'ring-blue-500'],
        'verify' => ['bg' => 'bg-purple-500', 'text' => 'text-purple-700 dark:text-purple-300', 'ring' => 'ring-purple-500'],
        'release' => ['bg' => 'bg-green-500', 'text' => 'text-green-700 dark:text-green-300', 'ring' => 'ring-green-500'],
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
            <div class="flex-shrink-0 w-6 h-px {{ $isCompleted || ($stage && !$isPending) ? 'bg-gray-400 dark:bg-gray-500' : 'bg-gray-200 dark:bg-gray-700' }}"></div>
        @endif

        <div class="flex items-center gap-1.5 {{ $isCurrent ? 'font-semibold' : '' }}" data-stage="{{ $name }}">
            {{-- Stage dot --}}
            @if ($isCompleted)
                <span class="flex-shrink-0 w-5 h-5 rounded-full {{ $colors['bg'] }} flex items-center justify-center">
                    <svg class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                </span>
            @elseif ($isRunning)
                <span class="flex-shrink-0 w-5 h-5 rounded-full {{ $colors['bg'] }} animate-pulse ring-2 {{ $colors['ring'] }} ring-offset-1 ring-offset-white dark:ring-offset-gray-800"></span>
            @elseif ($isAwaiting)
                <span class="flex-shrink-0 w-5 h-5 rounded-full bg-yellow-400 ring-2 ring-yellow-400 ring-offset-1 ring-offset-white dark:ring-offset-gray-800"></span>
            @elseif ($isFailed)
                <span class="flex-shrink-0 w-5 h-5 rounded-full bg-red-500 flex items-center justify-center">
                    <svg class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </span>
            @else
                <span class="flex-shrink-0 w-5 h-5 rounded-full bg-gray-200 dark:bg-gray-700 border-2 border-gray-300 dark:border-gray-600"></span>
            @endif

            {{-- Stage label --}}
            <span class="text-xs {{ $isCurrent ? $colors['text'] : 'text-gray-500 dark:text-gray-400' }}">
                {{ ucfirst($name) }}
            </span>
        </div>
    @endforeach

    @if ($run->iteration > 1)
        <span class="ml-2 text-xs text-indigo-600 dark:text-indigo-400 font-medium">↺ {{ $run->iteration }}</span>
    @endif
</div>
