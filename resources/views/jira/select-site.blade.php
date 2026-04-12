@extends('layouts.app')

@section('title', 'Select Jira Site')

@section('content')
    <div class="max-w-lg mx-auto">
        <h1 class="text-2xl font-headline font-bold mb-6">Select a Jira Site</h1>
        <p class="text-sm text-on-surface-variant mb-6">Multiple Jira sites were found. Choose the one you want to connect.</p>

        <div id="sites-list" class="space-y-2">
            <p class="text-sm text-outline">Loading sites...</p>
        </div>
    </div>

    <script>
        (async function() {
            try {
                const response = await fetch('/jira/sites', {
                    headers: { 'Accept': 'application/json' },
                });

                if (!response.ok) {
                    document.getElementById('sites-list').innerHTML =
                        '<p class="text-sm text-error">Failed to load sites. Please try connecting again.</p>';
                    return;
                }

                const data = await response.json();
                const container = document.getElementById('sites-list');
                container.innerHTML = '';

                data.sites.forEach(function(site) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '/jira/select-site';
                    form.innerHTML = '<input type="hidden" name="_token" value="' + document.querySelector('meta[name=csrf-token]').content + '">'
                        + '<input type="hidden" name="cloud_id" value="' + site.id + '">'
                        + '<button type="submit" class="w-full text-left px-4 py-3 bg-surface-container-low rounded-xl hover:bg-surface-container-high transition-colors">'
                        + '<span class="font-medium text-on-surface">' + site.name + '</span>'
                        + (site.url ? '<span class="block text-sm text-on-surface-variant">' + site.url + '</span>' : '')
                        + '</button>';
                    container.appendChild(form);
                });
            } catch (e) {
                document.getElementById('sites-list').innerHTML =
                    '<p class="text-sm text-error">Network error loading sites.</p>';
            }
        })();
    </script>
@endsection
