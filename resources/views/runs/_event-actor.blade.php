@switch($actor)
    @case('system')
        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-gray-200 dark:bg-gray-600 text-xs" title="System">S</span>
        @break
    @case('user')
        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-indigo-200 dark:bg-indigo-700 text-xs" title="User">U</span>
        @break
    @case('implement_agent')
        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-blue-200 dark:bg-blue-700 text-xs" title="Implement Agent">I</span>
        @break
    @case('verify_agent')
        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-200 dark:bg-cyan-700 text-xs" title="Verify Agent">V</span>
        @break
    @case('release_agent')
        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-green-200 dark:bg-green-700 text-xs" title="Release Agent">R</span>
        @break
    @case('preflight_agent')
        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-purple-200 dark:bg-purple-700 text-xs" title="Preflight Agent">P</span>
        @break
    @default
        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-gray-200 dark:bg-gray-600 text-xs" title="{{ $actor }}">?</span>
@endswitch
