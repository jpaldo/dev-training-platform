@props(['text' => null])

@php($content = is_scalar($text) ? (string) $text : '')
@if(blank($content))
    <span class="muted">Not provided</span>
@else
    <div {{ $attributes->merge(['class' => 'formatted-text markdown']) }}>{!! \App\Support\Markdown::toHtml($content) !!}</div>
@endif
