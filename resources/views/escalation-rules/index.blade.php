@extends('layouts.app')

@section('title', 'Escalation Rules')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-semibold">Escalation Rules</h1>
    <a href="{{ route('escalation-rules.create') }}" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">Add Rule</a>
</div>

@if ($rules->isEmpty())
    <p class="text-gray-500 dark:text-gray-400">No escalation rules configured. Rules force tighter autonomy when conditions match before stage transitions.</p>
@else
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Order</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Condition</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Target Level</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($rules as $rule)
                    <tr class="{{ $rule->is_enabled ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 text-sm">
                            <div class="flex items-center gap-1">
                                <form method="POST" action="{{ route('escalation-rules.move-up', $rule) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" title="Move up">&uarr;</button>
                                </form>
                                <form method="POST" action="{{ route('escalation-rules.move-down', $rule) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" title="Move down">&darr;</button>
                                </form>
                                <span class="ml-1 text-gray-500">{{ $rule->order }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm font-medium">{{ $rule->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-600 text-gray-800 dark:text-gray-200">
                                {{ str_replace('_', ' ', $rule->condition['type'] ?? 'unknown') }}
                            </span>
                            <span class="ml-1">{{ $rule->condition['value'] ?? '' }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                @if ($rule->target_level === \App\Enums\AutonomyLevel::Manual) bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200
                                @elseif ($rule->target_level === \App\Enums\AutonomyLevel::Supervised) bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-200
                                @else bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200
                                @endif">
                                {{ $rule->target_level->value }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <form method="POST" action="{{ route('escalation-rules.toggle', $rule) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-sm {{ $rule->is_enabled ? 'text-green-600 dark:text-green-400' : 'text-gray-400' }}">
                                    {{ $rule->is_enabled ? 'Enabled' : 'Disabled' }}
                                </button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-sm text-right">
                            <a href="{{ route('escalation-rules.edit', $rule) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline mr-3">Edit</a>
                            <form method="POST" action="{{ route('escalation-rules.destroy', $rule) }}" class="inline" onsubmit="return confirm('Delete this rule?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 dark:text-red-400 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
