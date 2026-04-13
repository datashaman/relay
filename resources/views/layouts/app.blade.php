<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">

        <title>{{ config('app.name', 'Relay') }} - @yield('title')</title>

        <script>
            (function () {
                try {
                    const stored = localStorage.getItem('relay-theme');
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    const dark = stored ? stored === 'dark' : prefersDark || true;
                    document.documentElement.classList.toggle('dark', dark);
                } catch (_) {}
            })();
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=space-grotesk:500,600,700|inter:400,500,600,700|jetbrains-mono:400,500,700&display=swap" rel="stylesheet" />

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <script src="https://cdn.tailwindcss.com"></script>
        @endif

        @livewireStyles
    </head>
    <body class="bg-background text-on-background min-h-screen font-body selection:bg-primary/30">
        @php
            $stuckCount = \App\Models\Run::where('status', \App\Enums\RunStatus::Stuck)->count();
            $currentPath = '/' . ltrim(request()->path(), '/');
            $tabs = [
                ['href' => '/',         'label' => 'Overview', 'matches' => ['/']],
                ['href' => '/activity', 'label' => 'Activity', 'matches' => ['/activity'], 'badge' => $stuckCount],
                ['href' => '/intake',   'label' => 'Intake',   'matches' => ['/intake']],
                ['href' => '/config',   'label' => 'Config',   'matches' => ['/config']],
            ];
            $isActiveTab = function (array $tab) use ($currentPath) {
                foreach ($tab['matches'] as $m) {
                    if ($currentPath === $m) return true;
                    if ($m !== '/' && str_starts_with($currentPath, $m)) return true;
                }
                return false;
            };
            $iconFor = [
                'Overview' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
                'Activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
                'Intake'   => '<path d="M4 14v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/>',
                'Config'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5h0a1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
            ];
        @endphp

        <header class="bg-surface-container-low sticky top-0 z-40">
            <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <a href="/" class="flex items-center gap-2">
                        <span class="inline-block w-2 h-2 rounded-full bg-stage-stuck"></span>
                        <span class="font-headline text-lg font-bold tracking-tight text-primary">Relay</span>
                    </a>
                    @if ($stuckCount > 0)
                        <a href="/activity" class="inline-flex items-center gap-1 rounded-full bg-stage-stuck/15 text-stage-stuck px-2 py-0.5 font-label text-[10px] uppercase tracking-widest hover:bg-stage-stuck/25">
                            <span class="w-1.5 h-1.5 rounded-full bg-stage-stuck animate-pulse"></span>
                            {{ $stuckCount }} Stuck
                        </a>
                    @endif
                </div>

                <div class="hidden md:flex items-center gap-1">
                    @foreach ($tabs as $tab)
                        @php $active = $isActiveTab($tab); @endphp
                        <a href="{{ $tab['href'] }}"
                           class="relative px-3 py-1.5 rounded-md font-label text-[11px] uppercase tracking-widest transition-colors
                           {{ $active ? 'text-primary bg-surface-container-high' : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-container-high' }}">
                            {{ $tab['label'] }}
                            @if (! empty($tab['badge']) && $tab['badge'] > 0)
                                <span class="ml-1 inline-flex items-center justify-center rounded-full bg-stage-stuck text-on-tertiary text-[10px] font-bold min-w-[1.25rem] h-4 px-1">{{ $tab['badge'] }}</span>
                            @endif
                        </a>
                    @endforeach

                    <button type="button" id="theme-toggle" aria-label="Toggle theme"
                            class="ml-2 p-2 rounded-md text-on-surface-variant hover:text-on-surface hover:bg-surface-container-high transition-colors">
                        <svg class="w-4 h-4 hidden dark-mode-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        <svg class="w-4 h-4 hidden light-mode-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                        </svg>
                    </button>
                </div>

                <button type="button" id="theme-toggle-mobile" aria-label="Toggle theme"
                        class="md:hidden p-2 rounded-md text-on-surface-variant hover:text-on-surface hover:bg-surface-container-high">
                    <svg class="w-5 h-5 hidden dark-mode-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    <svg class="w-5 h-5 hidden light-mode-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                    </svg>
                </button>
            </div>
        </header>

        <main class="@yield('container_class', 'max-w-5xl') mx-auto px-4 py-6 pb-28 md:pb-10">
            @yield('content')
        </main>

        {{-- Bottom tab bar (mobile only) --}}
        <nav class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-surface-container-low border-t border-outline-variant/20 pb-[env(safe-area-inset-bottom)]">
            <div class="flex items-stretch justify-around">
                @foreach ($tabs as $tab)
                    @php $active = $isActiveTab($tab); @endphp
                    <a href="{{ $tab['href'] }}"
                       class="relative flex flex-col items-center justify-center gap-1 py-2.5 flex-1 font-label text-[10px] uppercase tracking-widest
                       {{ $active ? 'text-primary' : 'text-on-surface-variant' }}">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            {!! $iconFor[$tab['label']] ?? '' !!}
                        </svg>
                        <span>{{ $tab['label'] }}</span>
                        @if (! empty($tab['badge']) && $tab['badge'] > 0)
                            <span class="absolute top-1.5 right-1/4 inline-flex items-center justify-center rounded-full bg-stage-stuck text-on-tertiary text-[9px] font-bold min-w-[1rem] h-4 px-1">{{ $tab['badge'] }}</span>
                        @endif
                        @if ($active)
                            <span class="absolute top-0 left-1/2 -translate-x-1/2 w-8 h-0.5 bg-primary rounded-full"></span>
                        @endif
                    </a>
                @endforeach
            </div>
        </nav>

        @php
            $toasts = array_filter([
                session('success') ? ['kind' => 'success', 'msg' => session('success')] : null,
                session('error') ? ['kind' => 'error', 'msg' => session('error')] : null,
                session('warning') ? ['kind' => 'warning', 'msg' => session('warning')] : null,
            ]);
        @endphp
        @if (! empty($toasts))
            <div id="toast-container" class="fixed left-1/2 -translate-x-1/2 bottom-24 md:bottom-4 md:left-auto md:right-4 md:translate-x-0 z-50 flex flex-col gap-2 w-[calc(100%-2rem)] md:w-auto md:max-w-sm pointer-events-none">
                @foreach ($toasts as $toast)
                    @php
                        $kind = $toast['kind'];
                        $cls = match ($kind) {
                            'success' => 'bg-surface-container-highest border-l-4 border-secondary text-secondary',
                            'error'   => 'bg-surface-container-highest border-l-4 border-error text-error',
                            default   => 'bg-surface-container-highest border-l-4 border-tertiary text-tertiary',
                        };
                    @endphp
                    <div class="relay-toast pointer-events-auto rounded-xl {{ $cls }} px-4 py-3 text-sm font-label shadow-lg shadow-black/40 flex items-start gap-3 transition-all duration-300"
                         data-kind="{{ $kind }}">
                        <span class="flex-1 leading-snug">{{ $toast['msg'] }}</span>
                        <button type="button" class="shrink-0 text-outline hover:text-on-surface" onclick="this.closest('.relay-toast').classList.add('opacity-0','translate-y-2');setTimeout(()=>this.closest('.relay-toast')?.remove(),300)" aria-label="Dismiss">×</button>
                    </div>
                @endforeach
            </div>
            <script>
                (function () {
                    document.querySelectorAll('.relay-toast').forEach((el, i) => {
                        setTimeout(() => {
                            el.classList.add('opacity-0', 'translate-y-2');
                            setTimeout(() => el.remove(), 300);
                        }, 4000 + i * 250);
                    });
                })();
            </script>
        @endif

        <script>
            function syncThemeIcons() {
                const isDark = document.documentElement.classList.contains('dark');
                document.querySelectorAll('.dark-mode-icon').forEach(el => el.classList.toggle('hidden', !isDark));
                document.querySelectorAll('.light-mode-icon').forEach(el => el.classList.toggle('hidden', isDark));
            }
            function toggleTheme() {
                const isDark = document.documentElement.classList.toggle('dark');
                try { localStorage.setItem('relay-theme', isDark ? 'dark' : 'light'); } catch (_) {}
                syncThemeIcons();
            }
            document.getElementById('theme-toggle')?.addEventListener('click', toggleTheme);
            document.getElementById('theme-toggle-mobile')?.addEventListener('click', toggleTheme);
            syncThemeIcons();
        </script>

        @livewireScripts
    </body>
</html>
