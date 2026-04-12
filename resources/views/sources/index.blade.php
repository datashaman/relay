@extends('layouts.app')

@section('title', 'Sources')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold">Sources</h1>
        <div class="flex gap-2">
            <a href="{{ route('oauth.redirect', 'github') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-700 text-white text-sm font-medium rounded-md hover:bg-gray-700 dark:hover:bg-gray-600">
                Connect GitHub
            </a>
            <a href="{{ route('oauth.redirect', 'jira') }}"
               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-500">
                Connect Jira
            </a>
        </div>
    </div>

    @if ($sources->isEmpty())
        <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
            <p class="text-gray-500 dark:text-gray-400 mb-4">No sources connected yet.</p>
            <p class="text-sm text-gray-400 dark:text-gray-500">Connect GitHub or Jira to start pulling issues into Relay.</p>
        </div>
    @else
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Account</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Last Synced</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($sources as $source)
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $source->type->value === 'github' ? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200' : 'bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-200' }}">
                                    {{ ucfirst($source->type->value) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                {{ $source->external_account ?? $source->name }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                {{ $source->last_synced_at ? $source->last_synced_at->diffForHumans() : 'Never' }}
                                @if ($source->sync_error)
                                    <div class="text-xs text-red-500 dark:text-red-400 mt-1 max-w-xs truncate" title="{{ $source->sync_error }}">
                                        Error: {{ Str::limit($source->sync_error, 60) }}
                                    </div>
                                    @if ($source->next_retry_at && $source->next_retry_at->isFuture())
                                        <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                            Retry {{ $source->next_retry_at->diffForHumans() }}
                                        </div>
                                    @endif
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if ($source->is_active)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-200">Active</span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900/50 text-red-800 dark:text-red-200">Inactive</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                <form action="{{ route('sources.sync', $source) }}" method="POST" class="inline mr-3">
                                    @csrf
                                    <button type="submit" class="text-green-600 dark:text-green-400 hover:text-green-900 dark:hover:text-green-300">
                                        Sync Now
                                    </button>
                                </form>
                                <button onclick="testConnection({{ $source->id }})"
                                        id="test-btn-{{ $source->id }}"
                                        class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300 mr-3">
                                    Test
                                </button>
                                <form action="{{ route('oauth.disconnect', $source->type->value) }}" method="POST" class="inline"
                                      onsubmit="return confirm('Are you sure you want to disconnect this source? This will revoke access and remove all associated data.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">
                                        Disconnect
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <tr id="test-result-{{ $source->id }}" class="hidden">
                            <td colspan="5" class="px-6 py-2"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <script>
        async function testConnection(sourceId) {
            const btn = document.getElementById('test-btn-' + sourceId);
            const resultRow = document.getElementById('test-result-' + sourceId);
            const resultCell = resultRow.querySelector('td');

            btn.textContent = 'Testing...';
            btn.disabled = true;

            try {
                const response = await fetch('/sources/' + sourceId + '/test', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                const data = await response.json();
                resultRow.classList.remove('hidden');

                if (data.success) {
                    resultCell.innerHTML = '<span class="text-sm text-green-600 dark:text-green-400">' + data.message + '</span>';
                } else {
                    resultCell.innerHTML = '<span class="text-sm text-red-600 dark:text-red-400">' + data.message + '</span>';
                }
            } catch (e) {
                resultRow.classList.remove('hidden');
                resultCell.innerHTML = '<span class="text-sm text-red-600 dark:text-red-400">Network error: could not reach server.</span>';
            } finally {
                btn.textContent = 'Test';
                btn.disabled = false;
            }
        }
    </script>
@endsection
