@extends('layouts.app')

@section('title', 'Give Guidance')

@section('content')
    <div class="mb-6">
        <a href="{{ route('stuck.index') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Back to Stuck Issues</a>
    </div>

    <h1 class="text-2xl font-bold mb-4">Give Guidance</h1>

    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 mb-6">
        <h2 class="text-lg font-semibold mb-2">{{ $run->issue->title }}</h2>
        <div class="flex items-center gap-3 text-sm text-gray-600 dark:text-gray-400 mb-3">
            <span class="inline-flex items-center rounded-full bg-amber-500 px-2 py-0.5 text-xs font-bold text-white">
                {{ $run->stuck_state->value }}
            </span>
            @if ($run->iteration > 0)
                <span>&circlearrowright; {{ $run->iteration }} iterations</span>
            @endif
        </div>

        @if ($run->preflight_doc)
            <details class="mt-3">
                <summary class="text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">Preflight Doc</summary>
                <div class="mt-2 prose prose-sm dark:prose-invert max-w-none bg-gray-50 dark:bg-gray-900 rounded p-3 text-xs">
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
                <summary class="text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">Failure Context</summary>
                <pre class="mt-2 bg-gray-50 dark:bg-gray-900 rounded p-3 text-xs overflow-x-auto">{{ json_encode($stuckEvent->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @endif
    </div>

    <form method="POST" action="{{ route('stuck.submit-guidance', $run) }}">
        @csrf
        <div class="mb-4">
            <label for="guidance" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Your guidance will be prepended to the agent's context on retry.
            </label>
            <textarea name="guidance" id="guidance" rows="6" required
                      class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-3 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                      placeholder="Describe what the agent should do differently...">{{ old('guidance') }}</textarea>
            @error('guidance')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>
        <div class="flex gap-3">
            <button type="submit"
                    class="rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-500">
                Submit Guidance &amp; Retry
            </button>
            <a href="{{ route('stuck.index') }}"
               class="rounded-md bg-gray-200 dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-600">
                Cancel
            </a>
        </div>
    </form>
@endsection
