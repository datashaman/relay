@extends('layouts.app')

@section('title', 'Edit Preflight Doc')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold">Edit Preflight Doc</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            {{ $run->issue->title }}
            @if ($run->issue->external_id)
                <span class="text-gray-400">({{ $run->issue->external_id }})</span>
            @endif
        </p>
    </div>

    <form method="POST" action="{{ route('preflight.doc.update', $run) }}">
        @csrf
        @method('PUT')

        <div class="mb-4">
            <textarea name="preflight_doc"
                      rows="30"
                      class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3 py-2 text-sm font-mono"
                      >{{ old('preflight_doc', $doc) }}</textarea>
            @error('preflight_doc')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex gap-2">
            <button type="submit"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Save Changes
            </button>
            <a href="{{ route('preflight.doc', $run) }}"
               class="rounded-md bg-gray-600 px-4 py-2 text-sm font-medium text-white hover:bg-gray-500">
                Cancel
            </a>
        </div>
    </form>
@endsection
