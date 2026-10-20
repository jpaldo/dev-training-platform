@props(['href' => null, 'active' => false, 'count' => 0])

@php
    $classes = [
        'flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border-0 px-2.5 py-2 text-left font-sans text-sm font-bold no-underline transition focus-visible:outline-3 focus-visible:outline-accent/50',
        'bg-brand-soft text-white shadow-[inset_3px_0_0_var(--color-brand)]' => $active,
        'bg-transparent text-muted hover:bg-white/5 hover:text-slate-100' => ! $active,
    ];
@endphp

@if($href)
    <a href="{{ $href }}" @if($active) aria-current="page" @endif {{ $attributes->class($classes) }}>
        <span>{{ $slot }}</span>
        @if($count > 0)<span class="rounded-full bg-danger px-2 py-0.5 text-[11px] font-black text-white">{{ $count }}</span>@endif
    </a>
@else
    <button type="submit" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
