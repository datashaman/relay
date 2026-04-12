@extends('layouts.app')

@section('title', 'Activity Feed')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Activity Feed</h1>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('activity.index') }}" class="mb-6">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="source" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Source</label>
                <select name="source" id="source" class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm px-3 py-1.5">
                    <option value="">All Sources</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source->id }}" @selected(request('source') == $source->id)>
                            {{ $source->external_account ?? $source->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="stage" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Stage</label>
                <select name="stage" id="stage" class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm px-3 py-1.5">
                    <option value="">All Stages</option>
                    @foreach (\App\Enums\StageName::cases() as $stageName)
                        <option value="{{ $stageName->value }}" @selected(request('stage') == $stageName->value)>
                            {{ ucfirst($stageName->value) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="actor" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Actor</label>
                <select name="actor" id="actor" class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm px-3 py-1.5">
                    <option value="">All Actors</option>
                    <option value="system" @selected(request('actor') == 'system')>System</option>
                    <option value="user" @selected(request('actor') == 'user')>User</option>
                    <option value="preflight_agent" @selected(request('actor') == 'preflight_agent')>Preflight Agent</option>
                    <option value="implement_agent" @selected(request('actor') == 'implement_agent')>Implement Agent</option>
                    <option value="verify_agent" @selected(request('actor') == 'verify_agent')>Verify Agent</option>
                    <option value="release_agent" @selected(request('actor') == 'release_agent')>Release Agent</option>
                </select>
            </div>

            <div>
                <label for="autonomy" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Autonomy</label>
                <select name="autonomy" id="autonomy" class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm px-3 py-1.5">
                    <option value="">All Levels</option>
                    @foreach (\App\Enums\AutonomyLevel::cases() as $level)
                        <option value="{{ $level->value }}" @selected(request('autonomy') == $level->value)>
                            {{ ucfirst($level->value) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="rounded-md bg-gray-800 dark:bg-gray-200 text-white dark:text-gray-800 px-3 py-1.5 text-sm font-medium hover:bg-gray-700 dark:hover:bg-gray-300">
                    Filter
                </button>
                @if (request()->hasAny(['source', 'stage', 'actor', 'autonomy']))
                    <a href="{{ route('activity.index') }}" class="rounded-md border border-gray-300 dark:border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                        Clear
                    </a>
                @endif
            </div>
        </div>
    </form>

    @if ($events->isEmpty())
        <div class="mt-8 text-center text-gray-500 dark:text-gray-400">
            <p class="text-lg">No activity yet.</p>
            <p class="text-sm mt-1">Events from agent runs and user actions will appear here.</p>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($events as $event)
                @php
                    $stage = $event->stage;
                    $run = $stage?->run;
                    $issue = $run?->issue;
                    $source = $issue?->source;
                @endphp
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-3">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start gap-2 flex-1 min-w-0">
                            @include('runs._event-actor', ['actor' => $event->actor])
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                        @include('runs._event-label', ['type' => $event->type])
                                    </span>
                                    @if ($stage)
                                        @php
                                            $stageColor = match ($stage->name->value) {
                                                'preflight' => 'purple',
                                                'implement' => 'blue',
                                                'verify' => 'cyan',
                                                'release' => 'green',
                                                default => 'gray',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center rounded-full bg-{{ $stageColor }}-100 text-{{ $stageColor }}-700 dark:bg-{{ $stageColor }}-900/30 dark:text-{{ $stageColor }}-300 px-2 py-0.5 text-xs font-medium">
                                            {{ ucfirst($stage->name->value) }}
                                        </span>
                                    @endif
                                </div>
                                @if ($issue)
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate">
                                        {{ $issue->title }}
                                        @if ($source)
                                            <span class="text-gray-400 dark:text-gray-500">&middot; {{ $source->external_account ?? $source->name }}</span>
                                        @endif
                                        @if ($run && $run->iteration > 1)
                                            <span class="text-gray-400 dark:text-gray-500">&middot; ↺ {{ $run->iteration }}</span>
                                        @endif
                                    </p>
                                @endif

                                @include('runs._event-payload', ['event' => $event])
                            </div>
                        </div>
                        <div class="flex items-center gap-2 ml-3 shrink-0">
                            <span class="text-xs text-gray-400 whitespace-nowrap">
                                {{ $event->created_at->diffForHumans() }}
                            </span>
                            @if ($run)
                                <a href="{{ route('runs.timeline', $run) }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline whitespace-nowrap" title="View run timeline">
                                    View →
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $events->links() }}
        </div>
    @endif
@endsection
