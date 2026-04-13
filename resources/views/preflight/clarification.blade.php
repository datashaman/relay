<x-layouts.app title="Preflight Clarification">
    <div class="mb-6">
        <h1 class="text-2xl font-headline font-bold">Preflight Clarification</h1>
        <p class="text-sm text-on-surface-variant mt-1">
            {{ $run->issue->title }}
            @if ($run->issue->external_id)
                <span class="text-outline">({{ $run->issue->external_id }})</span>
            @endif
        </p>
    </div>

    {{-- Known Facts Panel --}}
    @if (! empty($knownFacts))
        <div class="mb-6 rounded-xl bg-primary-container/30 p-4">
            <h2 class="text-lg font-headline font-semibold text-primary mb-2">Known Facts</h2>
            <ul class="list-disc list-inside space-y-1 text-sm text-on-surface">
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
                    <div class="rounded-xl bg-surface-container-low p-4">
                        <label class="block text-sm font-medium text-on-surface mb-2">
                            {{ $index + 1 }}. {{ $question['text'] }}
                        </label>

                        @if ($question['type'] === 'choice' && ! empty($question['options']))
                            <div class="space-y-2">
                                @foreach ($question['options'] as $option)
                                    <label class="flex items-center gap-2 text-sm text-on-surface-variant">
                                        <input type="radio"
                                               name="answer_{{ $question['id'] }}"
                                               value="{{ $option }}"
                                               class="text-primary">
                                        {{ $option }}
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <textarea name="answer_{{ $question['id'] }}"
                                      rows="3"
                                      placeholder="Your answer…"
                                      class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary"></textarea>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-6">
                <button type="submit"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-on-primary hover:bg-primary/90">
                    Submit Answers
                </button>
            </div>
        </form>

        <form method="POST" action="{{ route('preflight.skip', $run) }}" class="mt-3 inline">
            @csrf
            <button type="submit"
                    class="rounded-md bg-surface-container-high px-4 py-2 text-sm font-medium text-on-surface hover:bg-surface-container-highest">
                Skip to Doc
            </button>
        </form>
    @else
        <p class="text-on-surface-variant">No clarifying questions needed.</p>
    @endif
</x-layouts.app>
