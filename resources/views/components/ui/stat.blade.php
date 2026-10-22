@props(['label', 'value' => null, 'note' => null, 'tone' => null, 'size' => 'lg', 'variant' => 'card'])

{{-- One summary stat inside a `dl` grid: a standalone card by default, or a bordered row inside a card with `variant="field"`. A non-empty slot replaces the value `dd`. --}}
<div {{ $attributes->class([
    'grid min-w-0 content-start',
    'gap-2 rounded-2xl border border-line bg-surface p-4 sm:p-5' => $variant === 'card',
    'justify-items-start gap-2 rounded-xl border border-line bg-field px-4 py-3.5' => $variant === 'field',
]) }}>
    @if($variant === 'field')
        <dt><x-ui.eyebrow as="span">{{ $label }}</x-ui.eyebrow></dt>
    @else
        <dt class="text-xs font-black tracking-[0.12em] wrap-break-word text-muted uppercase">{{ $label }}</dt>
    @endif

    @if($slot->isNotEmpty())
        {{ $slot }}
    @elseif($tone)
        <dd class="m-0"><x-ui.badge :tone="$tone">{{ $value }}</x-ui.badge></dd>
    @else
        <dd @class([
            'm-0',
            'text-3xl font-black' => $size === 'lg',
            'text-xl/snug font-extrabold wrap-break-word' => $size === 'md',
        ])>{{ $value }}</dd>
    @endif

    @if($note)
        <dd class="m-0 text-sm text-muted">{{ $note }}</dd>
    @endif
</div>
