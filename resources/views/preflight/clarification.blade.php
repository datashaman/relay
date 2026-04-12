@extends('layouts.app')

@section('title', 'Preflight Clarification')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold">Preflight Clarification</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            {{ $run->issue->title }}
            @if ($run->issue->external_id)
                <span class="text-gray-400">({{ $run->issue->external_id }})</span>
            @endif
        </p>
    </div>

    {{-- Known Facts Panel --}}
    @if (! empty($knownFacts))
        <div class="mb-6 rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 p-4">
            <h2 class="text-lg font-semibold text-blue-800 dark:text-blue-200 mb-2">Known Facts</h2>
            <ul class="list-disc list-inside space-y-1 text-sm text-blue-900 dark:text-blue-100">
                @foreach ($knownFacts as $fact)
                    <li>{{ $fact }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Questions Form --}}
    @if (! empty($questions))
        <form method="POST" action="{{ route('preflight.submit-answers', $run) }}">
            @csrf
            <div class="space-y-6">
                @foreach ($questions as $index => $question)
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                        <label class="block text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">
                            {{ $index + 1 }}. {{ $question['text'] }}
                        </label>

                        @if ($question['type'] === 'choice' && ! empty($question['options']))
                            <div class="space-y-2">
                                @foreach ($question['options'] as $option)
                                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                        <input type="radio"
                                               name="answer_{{ $question['id'] }}"
                                               value="{{ $option }}"
                                               class="text-indigo-600">
                                        {{ $option }}
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <textarea name="answer_{{ $question['id'] }}"
                                      rows="3"
                                      placeholder="Your answer…"
                                      class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3 py-2 text-sm"></textarea>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-6">
                <button type="submit"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Submit Answers
                </button>
            </div>
        </form>

        <form method="POST" action="{{ route('preflight.skip', $run) }}" class="mt-3 inline">
            @csrf
            <button type="submit"
                    class="rounded-md bg-gray-600 px-4 py-2 text-sm font-medium text-white hover:bg-gray-500">
                Skip to Doc
            </button>
        </form>
    @else
        <p class="text-gray-500 dark:text-gray-400">No clarifying questions needed.</p>
    @endif
@endsection
