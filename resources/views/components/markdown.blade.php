@props(['text'])
@if(blank($text))
    @if(! $slot->isEmpty())
        <div {{ $attributes->merge(['class' => 'markdown']) }}>{{ $slot }}</div>
    @endif
@else
    <div {{ $attributes->merge(['class' => 'markdown']) }}>{!! \App\Support\Markdown::toHtml($text) !!}</div>
@endif