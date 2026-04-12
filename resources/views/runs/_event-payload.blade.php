@if (! empty($event->payload))
    <div class="mt-2">
        {{-- Failure report (from bounces and stuck states) --}}
        @if (! empty($event->payload['failure_report']))
            <details class="rounded bg-error-container/30 mt-1">
                <summary class="cursor-pointer px-3 py-1.5 text-xs font-medium text-error">
                    Failure Report
                </summary>
                <div class="px-3 pb-2 text-xs text-error whitespace-pre-wrap">@if (is_array($event->payload['failure_report']))@foreach ($event->payload['failure_report'] as $line){{ $line }}
@endforeach @else{{ $event->payload['failure_report'] }}@endif</div>
            </details>
        @endif

        {{-- Implementation summary and files --}}
        @if (! empty($event->payload['summary']))
            <p class="text-xs text-on-surface-variant mt-1">{{ $event->payload['summary'] }}</p>
        @endif

        @if (! empty($event->payload['files_changed']))
            <details class="rounded bg-surface-container mt-1">
                <summary class="cursor-pointer px-3 py-1.5 text-xs font-medium text-on-surface-variant">
                    Files changed ({{ count($event->payload['files_changed']) }})
                </summary>
                <div class="px-3 pb-2">
                    <ul class="text-xs text-on-surface-variant space-y-0.5">
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
                <code class="text-xs bg-surface-container-high rounded px-1.5 py-0.5 text-on-surface">{{ $event->payload['tool'] }}</code>
                @if (isset($event->payload['success']))
                    @if ($event->payload['success'])
                        <span class="text-xs text-secondary">✓</span>
                    @else
                        <span class="text-xs text-error">✗</span>
                    @endif
                @endif
            </div>
        @endif

        {{-- PR URL --}}
        @if (! empty($event->payload['pr_url']))
            <a href="{{ $event->payload['pr_url'] }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1 mt-1 text-xs text-secondary hover:underline">
                View Pull Request →
            </a>
        @endif

        {{-- Guidance text --}}
        @if (! empty($event->payload['guidance']))
            <div class="rounded bg-primary-container/30 px-3 py-2 mt-1">
                <p class="text-xs text-primary whitespace-pre-wrap">{{ $event->payload['guidance'] }}</p>
            </div>
        @endif

        {{-- Stuck state --}}
        @if (! empty($event->payload['stuck_state']))
            <span class="inline-flex items-center rounded-full bg-stage-stuck/20 text-stage-stuck px-2 py-0.5 font-label text-[10px] uppercase tracking-widest mt-1">
                {{ str_replace('_', ' ', ucfirst($event->payload['stuck_state'])) }}
            </span>
        @endif

        {{-- Autonomy level --}}
        @if (! empty($event->payload['autonomy_level']))
            <span class="text-xs text-on-surface-variant mt-1">
                Autonomy: {{ ucfirst($event->payload['autonomy_level']) }}
            </span>
        @endif

        {{-- Failure reason --}}
        @if (! empty($event->payload['reason']) && $event->type === 'failed')
            <p class="text-xs text-error mt-1">{{ $event->payload['reason'] }}</p>
        @endif

        {{-- Escalation rule details --}}
        @if ($event->type === 'escalation_rule_fired')
            <div class="rounded bg-stage-stuck/10 border-l-2 border-stage-stuck px-3 py-2 mt-1 space-y-0.5 font-label text-[11px]">
                @if (! empty($event->payload['rule_name']))
                    <div><span class="text-outline">RULE:</span> <span class="text-stage-stuck">{{ $event->payload['rule_name'] }}</span></div>
                @endif
                @if (! empty($event->payload['condition']))
                    <div><span class="text-outline">WHEN:</span> <span class="text-on-surface">{{ $event->payload['condition'] }}</span>
                        @if (isset($event->payload['observed_value']))
                            <span class="text-outline">(observed: {{ $event->payload['observed_value'] }})</span>
                        @endif
                    </div>
                @endif
                @if (! empty($event->payload['from_level']) && ! empty($event->payload['to_level']))
                    <div><span class="text-outline">LEVEL:</span>
                        <span class="text-on-surface-variant">{{ strtoupper($event->payload['from_level']) }}</span>
                        <span class="text-outline">→</span>
                        <span class="text-stage-stuck">{{ strtoupper($event->payload['to_level']) }}</span>
                    </div>
                @endif
            </div>
        @endif

        {{-- Clarification answers --}}
        @if ($event->type === 'clarification_answered' && ! empty($event->payload['answers']))
            <dl class="mt-1 rounded bg-secondary-container/20 px-3 py-2 space-y-1 text-xs">
                @foreach ($event->payload['answers'] as $qid => $answer)
                    <div class="flex gap-2">
                        <dt class="font-label text-[10px] text-outline uppercase tracking-widest shrink-0 min-w-16">{{ $qid }}</dt>
                        <dd class="text-on-surface">{{ $answer }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>
@endif
