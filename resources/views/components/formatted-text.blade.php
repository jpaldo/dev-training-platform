@props(['text' => null])

@php
    $content = is_scalar($text) ? (string) $text : '';
@endphp

@if(blank($content))
    <span class="text-muted">Not provided</span>
@else
    <div {{ $attributes->merge(['class' => 'formatted-text markdown']) }}>{!! \App\Support\Markdown::toHtml($content) !!}</div>
@endif
