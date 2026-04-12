@extends('layouts.app')

@section('title', 'Preflight Doc')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold">Preflight Doc</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            {{ $run->issue->title }}
            @if ($run->issue->external_id)
                <span class="text-gray-400">({{ $run->issue->external_id }})</span>
            @endif
        </p>
    </div>

    <div class="mb-4 flex gap-2">
        <a href="{{ route('preflight.doc.edit', $run) }}"
           class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            Edit Doc
        </a>
    </div>

    <div class="prose dark:prose-invert max-w-none rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-6">
        {!! nl2br(e($doc)) !!}
    </div>

    @if (! empty($history))
        <div class="mt-8">
            <h2 class="text-lg font-semibold mb-3">Doc History</h2>
            <div class="space-y-4">
                @foreach (array_reverse($history) as $index => $version)
                    <details class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                        <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                            Version {{ count($history) - $index }} — {{ $version['created_at'] }}
                            @if (isset($version['iteration']))
                                (iteration {{ $version['iteration'] }})
                            @endif
                        </summary>
                        <div class="px-4 pb-4 text-sm text-gray-600 dark:text-gray-400 whitespace-pre-wrap">{{ $version['doc'] }}</div>
                    </details>
                @endforeach
            </div>
        </div>
    @endif
@endsection
