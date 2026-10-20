@props(['as' => 'div', 'padding' => 'p-6'])

<{{ $as }} {{ $attributes->class(['rounded-2xl border border-line bg-surface', $padding]) }}>
    {{ $slot }}
</{{ $as }}>
