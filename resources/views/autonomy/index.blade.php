@extends('layouts.app')

@section('title', 'Configure Autonomy')

@section('content')
<h1 class="text-2xl font-semibold mb-6">Configure Autonomy</h1>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        {{-- Global Default --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6">
            <h2 class="text-lg font-medium mb-4">Global Default</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">The baseline autonomy level applied to all stages unless overridden.</p>

            <form method="POST" action="{{ route('autonomy.update-global') }}">
                @csrf
                <div class="space-y-3">
                    @foreach (\App\Enums\AutonomyLevel::cases() as $level)
                        <label class="flex items-start gap-3 p-3 rounded-md border cursor-pointer transition
                            {{ $globalDefault === $level ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20 dark:border-indigo-400' : 'border-gray-200 dark:border-gray-600 hover:border-gray-300 dark:hover:border-gray-500' }}">
                            <input type="radio" name="level" value="{{ $level->value }}" {{ $globalDefault === $level ? 'checked' : '' }}
                                class="mt-0.5 text-indigo-600" onchange="this.form.submit()">
                            <div>
                                <span class="text-sm font-medium">{{ ucfirst($level->value) }}</span>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    @switch($level)
                                        @case(\App\Enums\AutonomyLevel::Manual)
                                            Every action requires explicit approval. Full human control at each step.
                                            @break
                                        @case(\App\Enums\AutonomyLevel::Supervised)
                                            Agents work but pause for approval at stage transitions. Recommended starting point.
                                            @break
                                        @case(\App\Enums\AutonomyLevel::Assisted)
                                            Agents auto-advance through stages, pausing only on escalation rule matches.
                                            @break
                                        @case(\App\Enums\AutonomyLevel::Autonomous)
                                            Fully autonomous. Agents run the entire pipeline without pausing.
                                            @break
                                    @endswitch
                                </p>
                            </div>
                        </label>
                    @endforeach
                </div>
                @error('level')
                    <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </form>
        </div>

        {{-- Per-Stage Overrides --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6">
            <h2 class="text-lg font-medium mb-2">Per-Stage Overrides</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Stage overrides can only <strong>tighten</strong> from the global default ({{ ucfirst($globalDefault->value) }}). Select "Inherit" to use the global level.</p>

            <div class="space-y-4">
                @foreach (\App\Enums\StageName::cases() as $stage)
                    <form method="POST" action="{{ route('autonomy.update-stage', $stage->value) }}" class="flex items-center gap-4">
                        @csrf
                        <div class="w-28 text-sm font-medium">{{ ucfirst($stage->value) }}</div>
                        <select name="level" onchange="this.form.submit()"
                            class="flex-1 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="" {{ $stageOverrides[$stage->value] === null ? 'selected' : '' }}>Inherit ({{ ucfirst($globalDefault->value) }})</option>
                            @foreach (\App\Enums\AutonomyLevel::cases() as $level)
                                @if ($level->isTighterThanOrEqual($globalDefault))
                                    <option value="{{ $level->value }}" {{ $stageOverrides[$stage->value] === $level ? 'selected' : '' }}>
                                        {{ ucfirst($level->value) }}
                                    </option>
                                @endif
                            @endforeach
                        </select>
                        <div class="w-20 text-xs text-gray-400">
                            @if ($stageOverrides[$stage->value] !== null)
                                <span class="text-indigo-500 dark:text-indigo-400">overridden</span>
                            @endif
                        </div>
                    </form>
                @endforeach
            </div>
            @error('level')
                <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        {{-- Escalation Rules --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-medium">Escalation Rules</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Rules force tighter autonomy when conditions match before stage transitions.</p>
                </div>
                <a href="{{ route('escalation-rules.create') }}" class="px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">Add Rule</a>
            </div>

            @if ($rules->isEmpty())
                <p class="text-sm text-gray-400 dark:text-gray-500">No escalation rules configured.</p>
            @else
                <div class="overflow-hidden rounded-md border border-gray-200 dark:border-gray-600">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Order</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Name</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Condition</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Target</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                            @foreach ($rules as $rule)
                                <tr class="{{ $rule->is_enabled ? '' : 'opacity-50' }}">
                                    <td class="px-3 py-2 text-sm">
                                        <div class="flex items-center gap-1">
                                            <form method="POST" action="{{ route('escalation-rules.move-up', $rule) }}" class="inline">@csrf<button type="submit" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">&uarr;</button></form>
                                            <form method="POST" action="{{ route('escalation-rules.move-down', $rule) }}" class="inline">@csrf<button type="submit" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">&darr;</button></form>
                                            <span class="ml-1 text-gray-500 text-xs">{{ $rule->order }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-sm font-medium">{{ $rule->name }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-300">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-600 text-gray-800 dark:text-gray-200">{{ str_replace('_', ' ', $rule->condition['type'] ?? 'unknown') }}</span>
                                        <span class="ml-1 text-xs">{{ $rule->condition['value'] ?? '' }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-sm">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                            @if ($rule->target_level === \App\Enums\AutonomyLevel::Manual) bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200
                                            @elseif ($rule->target_level === \App\Enums\AutonomyLevel::Supervised) bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-200
                                            @else bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200
                                            @endif">{{ $rule->target_level->value }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-sm">
                                        <form method="POST" action="{{ route('escalation-rules.toggle', $rule) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs {{ $rule->is_enabled ? 'text-green-600 dark:text-green-400' : 'text-gray-400' }}">
                                                {{ $rule->is_enabled ? 'Enabled' : 'Disabled' }}
                                            </button>
                                        </form>
                                    </td>
                                    <td class="px-3 py-2 text-sm text-right">
                                        <a href="{{ route('escalation-rules.edit', $rule) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline text-xs mr-2">Edit</a>
                                        <form method="POST" action="{{ route('escalation-rules.destroy', $rule) }}" class="inline" onsubmit="return confirm('Delete this rule?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-red-600 dark:text-red-400 hover:underline text-xs">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Iteration Cap --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6">
            <h2 class="text-lg font-medium mb-2">Iteration Cap</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Maximum Verify-to-Implement bounce cycles before an issue is marked stuck.</p>

            <form method="POST" action="{{ route('autonomy.update-iteration-cap') }}" class="flex items-center gap-4">
                @csrf
                <input type="number" name="iteration_cap" value="{{ $iterationCap }}" min="1" max="20"
                    class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">Save</button>
                <span class="text-xs text-gray-400">Min 1, Max 20</span>
            </form>
            @error('iteration_cap')
                <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Preview Panel --}}
    <div class="lg:col-span-1">
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6 sticky top-6">
            <h2 class="text-lg font-medium mb-2">Effective Autonomy Preview</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Shows the resolved autonomy level for a sample issue at each stage, before escalation rules.</p>

            <div class="space-y-3">
                @foreach (\App\Enums\StageName::cases() as $stage)
                    @php
                        $effective = $stageOverrides[$stage->value]?->value ?? $globalDefault->value;
                        $isOverridden = $stageOverrides[$stage->value] !== null;
                    @endphp
                    <div class="flex items-center justify-between p-3 rounded-md bg-gray-50 dark:bg-gray-700/50">
                        <div>
                            <div class="text-sm font-medium">{{ ucfirst($stage->value) }}</div>
                            @if ($isOverridden)
                                <div class="text-xs text-indigo-500 dark:text-indigo-400">stage override</div>
                            @else
                                <div class="text-xs text-gray-400">from global</div>
                            @endif
                        </div>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium
                            @if ($effective === 'manual') bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200
                            @elseif ($effective === 'supervised') bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-200
                            @elseif ($effective === 'assisted') bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200
                            @else bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200
                            @endif">
                            {{ ucfirst($effective) }}
                        </span>
                    </div>
                @endforeach
            </div>

            <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-600">
                <h3 class="text-sm font-medium mb-2">Level Legend</h3>
                <div class="space-y-1.5 text-xs text-gray-500 dark:text-gray-400">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-red-500"></span>
                        <span><strong>Manual</strong> &mdash; Full approval required</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-yellow-500"></span>
                        <span><strong>Supervised</strong> &mdash; Approve at transitions</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                        <span><strong>Assisted</strong> &mdash; Auto-advance, escalation pauses</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-green-500"></span>
                        <span><strong>Autonomous</strong> &mdash; Fully automatic</span>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-600">
                <div class="text-xs text-gray-400">
                    <strong>Iteration cap:</strong> {{ $iterationCap }}
                </div>
                <div class="text-xs text-gray-400 mt-1">
                    <strong>Escalation rules:</strong> {{ $rules->where('is_enabled', true)->count() }} active
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
