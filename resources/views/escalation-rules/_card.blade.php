@php
    $targetClass = match ($rule->target_level) {
        \App\Enums\AutonomyLevel::Manual => 'bg-error-container/30 text-error',
        \App\Enums\AutonomyLevel::Supervised => 'bg-stage-stuck/20 text-stage-stuck',
        default => 'bg-primary-container/30 text-primary',
    };
    $order = $order ?? 1;
@endphp
<div class="bg-surface-container-lowest rounded-lg p-3 {{ $rule->is_enabled ? '' : 'opacity-50' }}"
     data-rule-card
     data-rule-id="{{ $rule->id }}"
     data-rule-name="{{ $rule->name }}"
     data-rule-condition-type="{{ $rule->condition['type'] ?? '' }}"
     data-rule-condition-operator="{{ $rule->condition['operator'] ?? '' }}"
     data-rule-condition-value="{{ $rule->condition['value'] ?? '' }}"
     data-rule-target-level="{{ $rule->target_level->value }}">
    <div class="flex items-start gap-3">
        <span data-rule-order class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-surface-container-high font-label text-[11px] text-on-surface-variant shrink-0 mt-0.5">
            {{ $order }}
        </span>
        <div class="flex-1 min-w-0 space-y-2">
            <h3 class="text-sm font-semibold text-on-surface leading-snug">{{ $rule->name }}</h3>
            @php
                $condOperator = $rule->condition['operator'] ?? null;
                $prettyOperator = match ($condOperator) {
                    '>=' => '≥',
                    '<=' => '≤',
                    '>', '<', '=' => $condOperator,
                    default => null,
                };
            @endphp
            <div class="flex items-baseline gap-2 flex-wrap">
                <span class="inline-flex items-center rounded bg-surface-container-high text-on-surface-variant px-1.5 py-0.5 font-label text-[10px] uppercase tracking-widest">
                    {{ str_replace('_', ' ', $rule->condition['type'] ?? 'unknown') }}
                </span>
                @if ($prettyOperator)
                    <span class="font-label text-[11px] text-primary">{{ $prettyOperator }}</span>
                @endif
                <span class="font-label text-[11px] text-on-surface-variant break-all">{{ $rule->condition['value'] ?? '' }}</span>
            </div>
            <div>
                <span class="inline-flex items-center rounded {{ $targetClass }} px-2 py-0.5 font-label text-[10px] uppercase tracking-widest">
                    → {{ $rule->target_level->value }}
                </span>
            </div>
        </div>
    </div>
    <div class="flex items-center justify-between gap-3 mt-3 pt-2 border-t border-outline-variant/20 leading-none">
        <form method="POST" action="{{ route('escalation-rules.toggle', $rule) }}" class="contents">
            @csrf
            <button type="submit" data-rule-toggle class="font-label text-[10px] uppercase tracking-widest leading-none {{ $rule->is_enabled ? 'text-secondary' : 'text-outline' }} hover:underline">
                {{ $rule->is_enabled ? 'Enabled' : 'Disabled' }}
            </button>
        </form>
        <div class="flex items-center gap-3">
            <form method="POST" action="{{ route('escalation-rules.move-up', $rule) }}" class="contents">
                @csrf
                <button type="submit" data-rule-move="up" class="font-label text-sm text-outline hover:text-on-surface leading-none" aria-label="Move up">↑</button>
            </form>
            <form method="POST" action="{{ route('escalation-rules.move-down', $rule) }}" class="contents">
                @csrf
                <button type="submit" data-rule-move="down" class="font-label text-sm text-outline hover:text-on-surface leading-none" aria-label="Move down">↓</button>
            </form>
            <button type="button" data-rule-edit class="font-label text-[10px] uppercase tracking-widest leading-none text-primary hover:underline">Edit</button>
            <form method="POST" action="{{ route('escalation-rules.destroy', $rule) }}" class="contents"
                  onsubmit="return confirm('Delete this rule?')">
                @csrf @method('DELETE')
                <button type="submit" class="font-label text-[10px] uppercase tracking-widest leading-none text-error hover:underline">Delete</button>
            </form>
        </div>
    </div>
</div>
