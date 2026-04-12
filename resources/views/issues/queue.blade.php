@extends('layouts.app')

@section('title', 'Issue Queue')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Issue Queue</h1>
    </div>

    <form method="GET" action="{{ route('issues.queue') }}" class="mb-6 flex gap-3 items-end">
        <div>
            <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Search</label>
            <input type="text" name="search" id="search" value="{{ request('search') }}"
                   placeholder="Title or external ID…"
                   class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-3 py-1.5 text-sm">
        </div>
        <div>
            <label for="source" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Source</label>
            <select name="source" id="source"
                    class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-3 py-1.5 text-sm">
                <option value="">All sources</option>
                @foreach ($sources as $source)
                    <option value="{{ $source->id }}" {{ request('source') == $source->id ? 'selected' : '' }}>
                        {{ $source->external_account ?? $source->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit"
                class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
            Filter
        </button>
        @if (request('search') || request('source'))
            <a href="{{ route('issues.queue') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">Clear</a>
        @endif
    </form>

    {{-- Pause intake controls --}}
    @foreach ($sources as $source)
        <div class="mb-2 flex items-center gap-3 text-sm">
            <span class="font-medium">{{ $source->external_account ?? $source->name }}</span>
            <form method="POST" action="{{ route('issues.toggle-pause', $source) }}" class="inline-flex items-center gap-2">
                @csrf
                @if (! $source->is_intake_paused)
                    <input type="number" name="backlog_threshold" placeholder="Threshold"
                           value="{{ $source->backlog_threshold }}"
                           class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-2 py-1 text-sm">
                @endif
                <button type="submit"
                        class="rounded-md px-2 py-1 text-xs font-medium {{ $source->is_intake_paused ? 'bg-green-600 text-white hover:bg-green-500' : 'bg-yellow-600 text-white hover:bg-yellow-500' }}">
                    {{ $source->is_intake_paused ? 'Resume Intake' : 'Pause Intake' }}
                </button>
            </form>
            @if ($source->is_intake_paused)
                <span class="inline-flex items-center rounded-full bg-yellow-100 dark:bg-yellow-900/30 px-2 py-0.5 text-xs font-medium text-yellow-800 dark:text-yellow-200">
                    Paused
                </span>
            @endif
        </div>
    @endforeach

    @if ($issues->isEmpty())
        <div class="mt-8 text-center text-gray-500 dark:text-gray-400">
            <p class="text-lg">No incoming issues.</p>
            <p class="text-sm mt-1">Issues will appear here after syncing your sources.</p>
        </div>
    @else
        @foreach ($groupedIssues as $sourceId => $sourceIssues)
            @php $source = $sources->firstWhere('id', $sourceId) ?? $sourceIssues->first()->source; @endphp
            <div class="mt-6">
                <h2 class="text-lg font-semibold mb-3 text-gray-700 dark:text-gray-300">
                    {{ $source->external_account ?? $source->name }}
                    <span class="text-sm font-normal text-gray-500">({{ $sourceIssues->count() }} issues)</span>
                </h2>

                <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Issue</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Labels</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                            @foreach ($sourceIssues as $issue)
                                <tr>
                                    <td class="px-4 py-3">
                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ $issue->title }}
                                            @if ($issue->auto_accepted)
                                                <span class="ml-1 inline-flex items-center rounded-full bg-blue-100 dark:bg-blue-900/30 px-2 py-0.5 text-xs font-medium text-blue-800 dark:text-blue-200">auto</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $issue->external_id }}
                                            @if ($issue->external_url)
                                                · <a href="{{ $issue->external_url }}" target="_blank" class="hover:underline">View</a>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($issue->status === \App\Enums\IssueStatus::Queued)
                                            <span class="inline-flex items-center rounded-full bg-orange-100 dark:bg-orange-900/30 px-2 py-0.5 text-xs font-medium text-orange-800 dark:text-orange-200">Queued</span>
                                        @elseif ($issue->status === \App\Enums\IssueStatus::Accepted)
                                            <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900/30 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">Accepted</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @foreach (($issue->labels ?? []) as $label)
                                            <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs text-gray-700 dark:text-gray-300 mr-1">{{ $label }}</span>
                                        @endforeach
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if ($issue->status === \App\Enums\IssueStatus::Queued)
                                            <form method="POST" action="{{ route('issues.accept', $issue) }}" class="inline">
                                                @csrf
                                                <button type="submit"
                                                        class="rounded-md bg-green-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-green-500">
                                                    Accept
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('issues.reject', $issue) }}" class="inline ml-1">
                                                @csrf
                                                <button type="submit"
                                                        class="rounded-md bg-red-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-red-500"
                                                        onclick="return confirm('Reject this issue?')">
                                                    Reject
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    @endif
@endsection
