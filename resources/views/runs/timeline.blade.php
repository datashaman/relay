<x-layouts.app title="Run Timeline">
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-headline font-bold">Run Timeline</h1>
                <p class="text-sm text-on-surface-variant mt-1">
                    {{ $run->issue->title }}
                    @if ($run->issue->external_id)
                        <span class="text-outline">({{ $run->issue->external_id }})</span>
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 font-label text-[10px] uppercase tracking-widest
                    @switch($run->status->value)
                        @case('completed') bg-secondary-container/30 text-secondary @break
                        @case('running') bg-stage-implement/20 text-stage-implement @break
                        @case('failed') bg-error-container/30 text-error @break
                        @case('stuck') bg-stage-stuck/20 text-stage-stuck @break
                        @default bg-surface-container-high text-on-surface-variant
                    @endswitch
                ">
                    {{ ucfirst($run->status->value) }}
                </span>
                @if ($run->iteration > 1)
                    <span class="inline-flex items-center rounded-full bg-primary-container/30 text-primary px-2.5 py-0.5 font-label text-[10px] uppercase tracking-widest">
                        ↺ {{ $run->iteration }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    @if ($prUrl)
        <div class="mb-6 rounded-xl bg-secondary-container/30 p-4">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-secondary" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                </svg>
                <a href="{{ $prUrl }}" target="_blank" rel="noopener" class="text-sm font-medium text-secondary hover:underline">
                    Pull Request: {{ $prUrl }}
                </a>
            </div>
        </div>
    @endif

    @php $latestIteration = max(array_keys($iterations ?: [0])); @endphp
    <div class="space-y-4">
        @foreach ($iterations as $iterationNum => $stages)
            @php
                $isLatest = $iterationNum === $latestIteration;
                $stageCount = is_countable($stages) ? count($stages) : 0;
                $eventCount = collect($stages)->sum(fn ($s) => $s->events->count());
            @endphp
            <details {{ $isLatest ? 'open' : '' }} class="group">
                <summary class="flex items-center gap-3 mb-3 cursor-pointer list-none">
                    <span class="inline-flex items-center justify-center w-5 h-5 rounded text-outline group-open:rotate-90 transition-transform font-label text-xs">▸</span>
                    <h2 class="text-lg font-headline font-semibold">Iteration {{ $iterationNum }}</h2>
                    <span class="font-label text-[10px] text-outline uppercase tracking-widest">
                        {{ $stageCount }} {{ \Illuminate\Support\Str::plural('stage', $stageCount) }} · {{ $eventCount }} {{ \Illuminate\Support\Str::plural('event', $eventCount) }}
                    </span>
                    <div class="flex-1 border-t border-outline-variant/40"></div>
                </summary>

                @if (empty($stages))
                    <p class="text-sm text-on-surface-variant italic">No stages recorded yet.</p>
                @else
                    <div class="space-y-4">
                        @foreach ($stages as $stage)
                            <div class="rounded-xl bg-surface-container-low overflow-hidden">
                                <div class="flex items-center justify-between px-4 py-3 border-b border-outline-variant/30
                                    @switch($stage->name->value)
                                        @case('preflight') bg-stage-preflight/10 @break
                                        @case('implement') bg-stage-implement/10 @break
                                        @case('verify') bg-stage-verify/10 @break
                                        @case('release') bg-stage-release/10 @break
                                    @endswitch
                                ">
                                    <div class="flex items-center gap-2">
                                        @switch($stage->name->value)
                                            @case('preflight')
                                                <span class="inline-block w-2.5 h-2.5 rounded-full bg-stage-preflight"></span>
                                                @break
                                            @case('implement')
                                                <span class="inline-block w-2.5 h-2.5 rounded-full bg-stage-implement"></span>
                                                @break
                                            @case('verify')
                                                <span class="inline-block w-2.5 h-2.5 rounded-full bg-stage-verify"></span>
                                                @break
                                            @case('release')
                                                <span class="inline-block w-2.5 h-2.5 rounded-full bg-stage-release"></span>
                                                @break
                                        @endswitch
                                        <span class="font-medium text-sm">{{ ucfirst($stage->name->value) }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 font-label text-[10px] uppercase tracking-widest
                                            @switch($stage->status->value)
                                                @case('completed') bg-secondary-container/30 text-secondary @break
                                                @case('running') bg-stage-implement/20 text-stage-implement @break
                                                @case('failed') bg-error-container/30 text-error @break
                                                @case('bounced') bg-stage-stuck/20 text-stage-stuck @break
                                                @case('stuck') bg-stage-stuck/20 text-stage-stuck @break
                                                @case('awaiting_approval') bg-stage-stuck/20 text-stage-stuck @break
                                                @default bg-surface-container-high text-on-surface-variant
                                            @endswitch
                                        ">
                                            {{ str_replace('_', ' ', ucfirst($stage->status->value)) }}
                                        </span>
                                        @if ($stage->started_at)
                                            <span class="text-xs text-outline">{{ $stage->started_at->format('M j, g:i A') }}</span>
                                        @endif
                                    </div>
                                </div>

                                @if ($stage->events->isNotEmpty())
                                    <div class="divide-y divide-outline-variant/30">
                                        @foreach ($stage->events as $event)
                                            <div class="px-4 py-3">
                                                <div class="flex items-start justify-between">
                                                    <div class="flex items-center gap-2">
                                                        @include('runs._event-actor', ['actor' => $event->actor])
                                                        <span class="text-sm font-medium text-on-surface">
                                                            @include('runs._event-label', ['type' => $event->type])
                                                        </span>
                                                    </div>
                                                    <span class="text-xs text-outline whitespace-nowrap ml-2">
                                                        {{ $event->created_at->format('g:i:s A') }}
                                                    </span>
                                                </div>

                                                @include('runs._event-payload', ['event' => $event])
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="px-4 py-3 text-sm text-on-surface-variant italic">
                                        No events recorded.
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </details>
        @endforeach
    </div>
</x-layouts.app>
