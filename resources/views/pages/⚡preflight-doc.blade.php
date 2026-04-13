<?php

use App\Models\Run;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Preflight Doc')]
#[Layout('components.layouts.app')]
class extends Component {
    public Run $run;

    public function mount(): void
    {
        $this->run->load('issue');

        if (! $this->run->preflight_doc) {
            session()->flash('error', 'No preflight doc generated for this run yet.');
            $this->redirectRoute('intake.index', navigate: true);
        }
    }

    public function with(): array
    {
        return [
            'doc' => $this->run->preflight_doc,
            'history' => $this->run->preflight_doc_history ?? [],
        ];
    }
};
?>

<div>
<div class="mb-6">
    <h1 class="text-2xl font-headline font-bold">Preflight Doc</h1>
    <p class="text-sm text-on-surface-variant mt-1">
        {{ $run->issue->title }}
        @if ($run->issue->external_id)
            <span class="text-outline">({{ $run->issue->external_id }})</span>
        @endif
    </p>
</div>

<div class="mb-4 flex gap-2">
    <a href="{{ route('preflight.doc.edit', $run) }}"
       class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-on-primary hover:bg-primary/90">
        Edit Doc
    </a>
</div>

<div class="prose dark:prose-invert max-w-none rounded-xl bg-surface-container-low p-6">
    {!! nl2br(e($doc)) !!}
</div>

@if (! empty($history))
    <div class="mt-8">
        <h2 class="text-lg font-headline font-semibold mb-3">Doc History</h2>
        <div class="space-y-4">
            @foreach (array_reverse($history) as $index => $version)
                <details class="rounded-xl bg-surface-container-low" wire:key="hist-{{ $index }}">
                    <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-on-surface-variant">
                        Version {{ count($history) - $index }} — {{ $version['created_at'] }}
                        @if (isset($version['iteration']))
                            (iteration {{ $version['iteration'] }})
                        @endif
                    </summary>
                    <div class="px-4 pb-4 text-sm text-on-surface-variant whitespace-pre-wrap">{{ $version['doc'] }}</div>
                </details>
            @endforeach
        </div>
    </div>
@endif
</div>
