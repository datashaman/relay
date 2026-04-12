@extends('layouts.app')

@section('title', 'Give Guidance')

@section('content')
    <div class="mb-6">
        <a href="{{ route('stuck.index') }}" class="text-sm text-primary hover:underline">&larr; Back to Stuck Issues</a>
    </div>

    <h1 class="text-2xl font-headline font-bold mb-4">Give Guidance</h1>

    <div class="rounded-xl bg-surface-container-low p-4 mb-6">
        <h2 class="text-lg font-headline font-semibold mb-2">{{ $run->issue->title }}</h2>
        <div class="flex items-center gap-3 text-sm text-on-surface-variant mb-3">
            <span class="inline-flex items-center rounded-full bg-stage-stuck px-2 py-0.5 font-label text-[10px] uppercase tracking-widest text-on-background">
                {{ $run->stuck_state->value }}
            </span>
            @if ($run->iteration > 0)
                <span>&circlearrowright; {{ $run->iteration }} iterations</span>
            @endif
        </div>

        @if ($run->preflight_doc)
            <details class="mt-3">
                <summary class="text-sm font-medium text-on-surface-variant cursor-pointer">Preflight Doc</summary>
                <div class="mt-2 prose prose-sm dark:prose-invert max-w-none bg-surface-container rounded p-3 text-xs">
                    {!! nl2br(e($run->preflight_doc)) !!}
                </div>
            </details>
        @endif

        @php
            $stuckEvent = $run->stages->flatMap->events->where('type', 'stuck')->last()
                ?? $run->stages->flatMap->events->where('type', 'iteration_cap_reached')->last();
        @endphp
        @if ($stuckEvent && ! empty($stuckEvent->payload))
            <details class="mt-3" open>
                <summary class="text-sm font-medium text-on-surface-variant cursor-pointer">Failure Context</summary>
                <pre class="mt-2 bg-surface-container rounded p-3 text-xs overflow-x-auto text-on-surface">{{ json_encode($stuckEvent->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @endif
    </div>

    <form method="POST" action="{{ route('stuck.submit-guidance', $run) }}">
        @csrf
        <div class="mb-4">
            <label for="guidance" class="block text-sm font-medium text-on-surface-variant mb-1">
                Your guidance will be prepended to the agent's context on retry.
            </label>
            <textarea name="guidance" id="guidance" rows="6" required
                      class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm focus:ring-primary focus:border-primary"
                      placeholder="Describe what the agent should do differently...">{{ old('guidance') }}</textarea>
            @error('guidance')
                <p class="mt-1 text-sm text-error">{{ $message }}</p>
            @enderror
        </div>
        <div class="flex gap-3">
            <button type="submit"
                    class="rounded-md bg-stage-stuck px-4 py-2 text-sm font-medium text-on-background hover:bg-stage-stuck/90">
                Submit Guidance &amp; Retry
            </button>
            <a href="{{ route('stuck.index') }}"
               class="rounded-md bg-surface-container-high px-4 py-2 text-sm font-medium text-on-surface hover:bg-surface-container-highest">
                Cancel
            </a>
        </div>
    </form>
@endsection
