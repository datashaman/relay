<?php

use App\Models\Run;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Edit Preflight Doc')]
#[Layout('layouts::app')]
class extends Component {
    public Run $run;

    public string $preflightDoc = '';

    public function mount(): void
    {
        $this->run->load('issue');

        if (! $this->run->preflight_doc) {
            session()->flash('error', 'No preflight doc generated for this run yet.');
            $this->redirectRoute('intake.index', navigate: true);

            return;
        }

        $this->preflightDoc = $this->run->preflight_doc;
    }

    public function save()
    {
        $this->validate([
            'preflightDoc' => 'required|string',
        ]);

        $history = $this->run->preflight_doc_history ?? [];
        $history[] = [
            'doc' => $this->run->preflight_doc,
            'created_at' => now()->toIso8601String(),
            'iteration' => $this->run->iteration,
        ];

        $this->run->update([
            'preflight_doc' => $this->preflightDoc,
            'preflight_doc_history' => $history,
        ]);

        session()->flash('success', 'Preflight doc updated.');

        return $this->redirectRoute('preflight.doc', $this->run, navigate: true);
    }
};
?>

<div>
<div class="mb-6">
    <h1 class="text-2xl font-headline font-bold">Edit Preflight Doc</h1>
    <p class="text-sm text-on-surface-variant mt-1">
        {{ $run->issue->title }}
        @if ($run->issue->external_id)
            <span class="text-outline">({{ $run->issue->external_id }})</span>
        @endif
    </p>
</div>

<form wire:submit="save">
    <div class="mb-4">
        <textarea wire:model="preflightDoc"
                  rows="30"
                  class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm font-mono focus:border-primary focus:ring-primary"></textarea>
        @error('preflightDoc')
            <p class="mt-1 text-sm text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="flex gap-2">
        <button type="submit"
                class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-on-primary hover:bg-primary/90">
            Save Changes
        </button>
        <a href="{{ route('preflight.doc', $run) }}"
           class="rounded-md bg-surface-container-high px-4 py-2 text-sm font-medium text-on-surface hover:bg-surface-container-highest">
            Cancel
        </a>
    </div>
</form>
</div>
