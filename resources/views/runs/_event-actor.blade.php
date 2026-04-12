@php
    $actorMeta = match ($actor) {
        'user' => ['label' => 'Human Action', 'bg' => 'bg-secondary-container/30', 'fg' => 'text-secondary', 'glyph' => '●'],
        'system' => ['label' => 'System Alert', 'bg' => 'bg-stage-stuck/20', 'fg' => 'text-stage-stuck', 'glyph' => '!'],
        'preflight_agent' => ['label' => 'Preflight Agent', 'bg' => 'bg-stage-preflight/25', 'fg' => 'text-stage-preflight', 'glyph' => 'P'],
        'implement_agent' => ['label' => 'Implement Agent', 'bg' => 'bg-stage-implement/25', 'fg' => 'text-stage-implement', 'glyph' => 'I'],
        'verify_agent' => ['label' => 'Verify Agent', 'bg' => 'bg-stage-verify/25', 'fg' => 'text-stage-verify', 'glyph' => 'V'],
        'release_agent' => ['label' => 'Release Agent', 'bg' => 'bg-stage-release/25', 'fg' => 'text-stage-release', 'glyph' => 'R'],
        default => ['label' => $actor, 'bg' => 'bg-surface-container-high', 'fg' => 'text-on-surface-variant', 'glyph' => '?'],
    };
@endphp
<span class="inline-flex items-center justify-center w-8 h-8 rounded-full {{ $actorMeta['bg'] }} {{ $actorMeta['fg'] }} font-label text-sm font-bold shrink-0" title="{{ $actorMeta['label'] }}">
    {{ $actorMeta['glyph'] }}
</span>
