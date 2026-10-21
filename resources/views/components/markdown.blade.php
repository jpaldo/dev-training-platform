@props(['text'])

@if(filled($text))
    <div {{ $attributes->merge(['class' => 'markdown']) }}>{!! \App\Support\Markdown::toHtml($text) !!}</div>
@elseif($slot->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'markdown']) }}>{{ $slot }}</div>
@endif
