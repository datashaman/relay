<?php

use App\Enums\StageStatus;
use App\Models\Run;
use App\Services\OrchestratorService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Preflight Clarification')]
#[Layout('components.layouts.app')]
class extends Component {
    public Run $run;

    public array $answers = [];

    public function mount(): void
    {
        $this->run->load('issue');

        if (! $this->stage()) {
            session()->flash('error', 'No pending clarification for this run.');
            $this->redirectRoute('intake.index', navigate: true);

            return;
        }

        foreach ($this->run->clarification_questions ?? [] as $q) {
            $this->answers[$q['id']] = '';
        }
    }

    public function submitAnswers(OrchestratorService $orchestrator)
    {
        $stage = $this->stage();
        if (! $stage) {
            return $this->redirectRoute('intake.index', navigate: true);
        }

        $answers = array_filter($this->answers, fn ($v) => $v !== null && $v !== '');
        $this->run->update(['clarification_answers' => $answers]);
        $orchestrator->resume($stage);

        session()->flash('success', "Answers submitted for \"{$this->run->issue->title}\". Preflight resuming.");

        return $this->redirectRoute('intake.index', navigate: true);
    }

    public function skipToDoc(OrchestratorService $orchestrator)
    {
        $stage = $this->stage();
        if (! $stage) {
            return $this->redirectRoute('intake.index', navigate: true);
        }

        $orchestrator->resume($stage, ['skip_to_doc' => true]);
        session()->flash('success', "Skipped to doc for \"{$this->run->issue->title}\". Preflight resuming.");

        return $this->redirectRoute('intake.index', navigate: true);
    }

    private function stage()
    {
        return $this->run->stages()
            ->where('name', 'preflight')
            ->where('status', StageStatus::AwaitingApproval)
            ->latest()
            ->first();
    }

    public function with(): array
    {
        return [
            'knownFacts' => $this->run->known_facts ?? [],
            'questions' => $this->run->clarification_questions ?? [],
        ];
    }
};
?>

<div>
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

{{-- Questions --}}
@if (! empty($questions))
    <form wire:submit="submitAnswers">
        <div class="space-y-6">
            @foreach ($questions as $index => $question)
                <div class="rounded-xl bg-surface-container-low p-4" wire:key="q-{{ $question['id'] }}">
                    <label class="block text-sm font-medium text-on-surface mb-2">
                        {{ $index + 1 }}. {{ $question['text'] }}
                    </label>

                    @if ($question['type'] === 'choice' && ! empty($question['options']))
                        <div class="space-y-2">
                            @foreach ($question['options'] as $option)
                                <label class="flex items-center gap-2 text-sm text-on-surface-variant">
                                    <input type="radio"
                                           wire:model="answers.{{ $question['id'] }}"
                                           value="{{ $option }}"
                                           class="text-primary">
                                    {{ $option }}
                                </label>
                            @endforeach
                        </div>
                    @else
                        <textarea wire:model="answers.{{ $question['id'] }}"
                                  rows="3"
                                  placeholder="Your answer…"
                                  class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary"></textarea>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-on-primary hover:bg-primary/90">
                Submit Answers
            </button>
            <button type="button" wire:click="skipToDoc"
                    class="rounded-md bg-surface-container-high px-4 py-2 text-sm font-medium text-on-surface hover:bg-surface-container-highest">
                Skip to Doc
            </button>
        </div>
    </form>
@else
    <p class="text-on-surface-variant">No clarifying questions needed.</p>
@endif
</div>
