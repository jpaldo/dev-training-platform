@props(['href' => null, 'variant' => 'primary', 'type' => 'button', 'disabled' => false])

@php
    $classes = $attributes->class([
        'inline-flex cursor-pointer items-center justify-center gap-2 rounded-xl border px-4 py-2.5 font-sans text-sm font-extrabold text-white no-underline transition focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50',
        'border-transparent bg-linear-to-r from-brand to-brand-deep hover:brightness-110' => $variant === 'primary',
        'border-line bg-raised hover:border-brand' => $variant === 'secondary',
        'cursor-not-allowed opacity-55' => $disabled,
    ])->get('class');
    $attributes = $attributes->except('class');
@endphp

@if($href && ! $disabled)
    <a href="{{ $href }}" {{ $attributes }} class="{{ $classes }}">{{ $slot }}</a>
@elseif($href)
    <span aria-disabled="true" {{ $attributes }} class="{{ $classes }}">{{ $slot }}</span>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes }} class="{{ $classes }}">{{ $slot }}</button>
@endif
