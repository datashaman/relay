@extends('layouts.app')

@section('title', $rule ? 'Edit Escalation Rule' : 'Add Escalation Rule')

@section('content')
<div class="max-w-lg">
    <h1 class="text-2xl font-semibold mb-6">{{ $rule ? 'Edit Escalation Rule' : 'Add Escalation Rule' }}</h1>

    @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 p-4">
            <ul class="list-disc list-inside text-sm text-red-800 dark:text-red-200">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $rule ? route('escalation-rules.update', $rule) : route('escalation-rules.store') }}" class="space-y-5">
        @csrf
        @if ($rule)
            @method('PUT')
        @endif

        <div>
            <label for="name" class="block text-sm font-medium mb-1">Rule Name</label>
            <input type="text" name="name" id="name" value="{{ old('name', $rule?->name) }}" required
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm">
        </div>

        <div>
            <label for="condition_type" class="block text-sm font-medium mb-1">Condition Type</label>
            <select name="condition_type" id="condition_type" required
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm">
                @php $currentType = old('condition_type', $rule?->condition['type'] ?? ''); @endphp
                <option value="label_match" {{ $currentType === 'label_match' ? 'selected' : '' }}>Label Match</option>
                <option value="file_path_match" {{ $currentType === 'file_path_match' ? 'selected' : '' }}>File Path Match</option>
                <option value="diff_size" {{ $currentType === 'diff_size' ? 'selected' : '' }}>Diff Size (threshold)</option>
                <option value="touched_directory_match" {{ $currentType === 'touched_directory_match' ? 'selected' : '' }}>Touched Directory Match</option>
            </select>
        </div>

        <div>
            <label for="condition_value" class="block text-sm font-medium mb-1">Condition Value</label>
            <input type="text" name="condition_value" id="condition_value" value="{{ old('condition_value', $rule?->condition['value'] ?? '') }}" required
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm"
                placeholder="e.g., security, src/config/*, 500, database/">
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">For labels: exact label name. For file paths: glob pattern. For diff size: line count threshold. For directories: directory path.</p>
        </div>

        <div>
            <label for="target_level" class="block text-sm font-medium mb-1">Target Autonomy Level</label>
            <select name="target_level" id="target_level" required
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm">
                @foreach ($levels as $level)
                    <option value="{{ $level->value }}" {{ old('target_level', $rule?->target_level?->value) === $level->value ? 'selected' : '' }}>
                        {{ ucfirst($level->value) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                {{ $rule ? 'Update Rule' : 'Create Rule' }}
            </button>
            <a href="{{ route('escalation-rules.index') }}" class="text-sm text-gray-600 dark:text-gray-300 hover:underline">Cancel</a>
        </div>
    </form>
</div>
@endsection
