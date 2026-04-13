@props(['actor'])

@php
    $actorMeta = match ($actor) {
        'user' => ['label' => 'Human Action', 'bg' => 'bg-secondary-container/30', 'fg' => 'text-secondary', 'icon' => 'user'],
        'system' => ['label' => 'System Alert', 'bg' => 'bg-stage-stuck/20', 'fg' => 'text-stage-stuck', 'icon' => 'alert'],
        'preflight_agent' => ['label' => 'Preflight Agent', 'bg' => 'bg-stage-preflight/25', 'fg' => 'text-stage-preflight', 'icon' => 'clipboard'],
        'implement_agent' => ['label' => 'Implement Agent', 'bg' => 'bg-stage-implement/25', 'fg' => 'text-stage-implement', 'icon' => 'hammer'],
        'verify_agent' => ['label' => 'Verify Agent', 'bg' => 'bg-stage-verify/25', 'fg' => 'text-stage-verify', 'icon' => 'check'],
        'release_agent' => ['label' => 'Release Agent', 'bg' => 'bg-stage-release/25', 'fg' => 'text-stage-release', 'icon' => 'rocket'],
        default => ['label' => $actor, 'bg' => 'bg-surface-container-high', 'fg' => 'text-on-surface-variant', 'icon' => 'question'],
    };
    $iconPaths = [
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 14 0v1"/>',
        'alert'     => '<path d="M12 2 L22 20 H2 Z"/><line x1="12" y1="9" x2="12" y2="13"/><circle cx="12" cy="17" r="0.5" fill="currentColor"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><rect x="9" y="2" width="6" height="4" rx="1"/><line x1="8" y1="11" x2="16" y2="11"/><line x1="8" y1="15" x2="14" y2="15"/>',
        'hammer'    => '<path d="M14 3 L21 10 L17 14 L10 7 Z"/><line x1="10" y1="7" x2="3" y2="14"/><line x1="3" y1="14" x2="7" y2="18"/>',
        'check'     => '<polyline points="4 12 10 18 20 6"/>',
        'rocket'    => '<path d="M12 2 C16 6 18 10 18 14 L12 20 L6 14 C6 10 8 6 12 2 Z"/><circle cx="12" cy="11" r="2"/><path d="M8 18 L6 22"/><path d="M16 18 L18 22"/>',
        'question'  => '<circle cx="12" cy="12" r="10"/><path d="M9 9a3 3 0 1 1 5 2c-1 1-2 1-2 3"/><circle cx="12" cy="17" r="0.5" fill="currentColor"/>',
    ];
@endphp
<span class="inline-flex items-center justify-center w-8 h-8 rounded-full {{ $actorMeta['bg'] }} {{ $actorMeta['fg'] }} shrink-0" title="{{ $actorMeta['label'] }}">
    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        {!! $iconPaths[$actorMeta['icon']] ?? $iconPaths['question'] !!}
    </svg>
</span>
