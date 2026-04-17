@props(['content' => '', 'class' => 'prose dark:prose-invert max-w-none'])

{{-- Render markdown with raw HTML escaped. Preflight doc content can come
     from an LLM; treat as untrusted and disable unsafe links. --}}
<div {{ $attributes->class($class) }}>
    {!! \Illuminate\Support\Str::markdown((string) $content, [
        'html_input' => 'escape',
        'allow_unsafe_links' => false,
    ]) !!}
</div>
