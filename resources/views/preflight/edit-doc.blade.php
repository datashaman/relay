@extends('layouts.app')

@section('title', 'Edit Preflight Doc')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-headline font-bold">Edit Preflight Doc</h1>
        <p class="text-sm text-on-surface-variant mt-1">
            {{ $run->issue->title }}
            @if ($run->issue->external_id)
                <span class="text-outline">({{ $run->issue->external_id }})</span>
            @endif
        </p>
    </div>

    <form method="POST" action="{{ route('preflight.doc.update', $run) }}">
        @csrf
        @method('PUT')

        <div class="mb-4">
            <textarea name="preflight_doc"
                      rows="30"
                      class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface px-3 py-2 text-sm font-mono focus:border-primary focus:ring-primary"
                      >{{ old('preflight_doc', $doc) }}</textarea>
            @error('preflight_doc')
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
@endsection
