@if (! empty($event->payload))
    <div class="mt-2">
        {{-- Failure report (from bounces and stuck states) --}}
        @if (! empty($event->payload['failure_report']))
            <details class="rounded border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 mt-1">
                <summary class="cursor-pointer px-3 py-1.5 text-xs font-medium text-red-700 dark:text-red-300">
                    Failure Report
                </summary>
                <div class="px-3 pb-2 text-xs text-red-600 dark:text-red-400 whitespace-pre-wrap">@if (is_array($event->payload['failure_report']))@foreach ($event->payload['failure_report'] as $line){{ $line }}
@endforeach @else{{ $event->payload['failure_report'] }}@endif</div>
            </details>
        @endif

        {{-- Implementation summary and files --}}
        @if (! empty($event->payload['summary']))
            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">{{ $event->payload['summary'] }}</p>
        @endif

        @if (! empty($event->payload['files_changed']))
            <details class="rounded border border-gray-200 dark:border-gray-700 mt-1">
                <summary class="cursor-pointer px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400">
                    Files changed ({{ count($event->payload['files_changed']) }})
                </summary>
                <div class="px-3 pb-2">
                    <ul class="text-xs text-gray-500 dark:text-gray-400 space-y-0.5">
                        @foreach ($event->payload['files_changed'] as $file)
                            <li class="font-mono">{{ $file }}</li>
                        @endforeach
                    </ul>
                </div>
            </details>
        @endif

        {{-- Tool call details --}}
        @if ($event->type === 'tool_call' && ! empty($event->payload['tool']))
            <div class="flex items-center gap-2 mt-1">
                <code class="text-xs bg-gray-100 dark:bg-gray-700 rounded px-1.5 py-0.5">{{ $event->payload['tool'] }}</code>
                @if (isset($event->payload['success']))
                    @if ($event->payload['success'])
                        <span class="text-xs text-green-600 dark:text-green-400">✓</span>
                    @else
                        <span class="text-xs text-red-600 dark:text-red-400">✗</span>
                    @endif
                @endif
            </div>
        @endif

        {{-- PR URL --}}
        @if (! empty($event->payload['pr_url']))
            <a href="{{ $event->payload['pr_url'] }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1 mt-1 text-xs text-green-600 dark:text-green-400 hover:underline">
                View Pull Request →
            </a>
        @endif

        {{-- Guidance text --}}
        @if (! empty($event->payload['guidance']))
            <div class="rounded border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/20 px-3 py-2 mt-1">
                <p class="text-xs text-indigo-700 dark:text-indigo-300 whitespace-pre-wrap">{{ $event->payload['guidance'] }}</p>
            </div>
        @endif

        {{-- Stuck state --}}
        @if (! empty($event->payload['stuck_state']))
            <span class="inline-flex items-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 px-2 py-0.5 text-xs mt-1">
                {{ str_replace('_', ' ', ucfirst($event->payload['stuck_state'])) }}
            </span>
        @endif

        {{-- Autonomy level --}}
        @if (! empty($event->payload['autonomy_level']))
            <span class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Autonomy: {{ ucfirst($event->payload['autonomy_level']) }}
            </span>
        @endif

        {{-- Failure reason --}}
        @if (! empty($event->payload['reason']) && $event->type === 'failed')
            <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $event->payload['reason'] }}</p>
        @endif
    </div>
@endif
