@props(['as' => 'div'])

<{{ $as }} {{ $attributes->class(['text-xs font-black tracking-[0.12em] text-accent uppercase']) }}>{{ $slot }}</{{ $as }}>
