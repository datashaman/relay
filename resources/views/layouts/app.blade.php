<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">

        <title>{{ config('app.name', 'Relay') }} - @yield('title')</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <script src="https://cdn.tailwindcss.com"></script>
        @endif
    </head>
    <body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen font-sans">
        <nav class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
            <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
                <a href="/" class="text-lg font-semibold">Relay</a>

                <div class="hidden md:flex items-center gap-4" id="desktop-nav">
                    <a href="/issues" class="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">Issues</a>
                    <a href="/issues/queue" class="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">Queue</a>
                    <a href="/sources" class="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">Sources</a>
                    <a href="/autonomy" class="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">Autonomy</a>
                    @php $stuckCount = \App\Models\Run::where('status', \App\Enums\RunStatus::Stuck)->count(); @endphp
                    <a href="/activity" class="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">
                        Activity
                        @if ($stuckCount > 0)
                            <span class="ml-0.5 inline-flex items-center justify-center rounded-full bg-amber-500 text-white text-xs font-bold min-w-[1.25rem] h-5 px-1">{{ $stuckCount }} stuck</span>
                        @endif
                    </a>
                    <a href="/stuck" class="text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">
                        Stuck
                        @if ($stuckCount > 0)
                            <span class="ml-0.5 inline-flex items-center justify-center rounded-full bg-amber-500 text-white text-xs font-bold min-w-[1.25rem] h-5 px-1">{{ $stuckCount }}</span>
                        @endif
                    </a>
                </div>

                <button type="button" id="mobile-menu-toggle" class="md:hidden p-2 rounded-md text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Toggle menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>

            <div id="mobile-nav" class="hidden md:hidden border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                @php $stuckCount = $stuckCount ?? \App\Models\Run::where('status', \App\Enums\RunStatus::Stuck)->count(); @endphp
                <div class="px-4 py-2 space-y-1">
                    <a href="/issues" class="block py-2 text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">Issues</a>
                    <a href="/issues/queue" class="block py-2 text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">Queue</a>
                    <a href="/sources" class="block py-2 text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">Sources</a>
                    <a href="/autonomy" class="block py-2 text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">Autonomy</a>
                    <a href="/activity" class="block py-2 text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">
                        Activity
                        @if ($stuckCount > 0)
                            <span class="ml-0.5 inline-flex items-center justify-center rounded-full bg-amber-500 text-white text-xs font-bold min-w-[1.25rem] h-5 px-1">{{ $stuckCount }} stuck</span>
                        @endif
                    </a>
                    <a href="/stuck" class="block py-2 text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">
                        Stuck
                        @if ($stuckCount > 0)
                            <span class="ml-0.5 inline-flex items-center justify-center rounded-full bg-amber-500 text-white text-xs font-bold min-w-[1.25rem] h-5 px-1">{{ $stuckCount }}</span>
                        @endif
                    </a>
                </div>
            </div>
        </nav>

        <main class="@yield('container_class', 'max-w-5xl') mx-auto px-4 py-8">
            @if (session('success'))
                <div class="mb-4 rounded-md bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 p-4 text-sm text-green-800 dark:text-green-200">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 p-4 text-sm text-red-800 dark:text-red-200">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('warning'))
                <div class="mb-4 rounded-md bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-800 p-4 text-sm text-yellow-800 dark:text-yellow-200">
                    {{ session('warning') }}
                </div>
            @endif

            @yield('content')
        </main>

        <script>
            document.getElementById('mobile-menu-toggle')?.addEventListener('click', function() {
                document.getElementById('mobile-nav')?.classList.toggle('hidden');
            });
        </script>
    </body>
</html>
