@props(['label', 'value' => null, 'note' => null, 'tone' => null, 'size' => 'lg', 'variant' => 'card', 'href' => null, 'linkLabel' => null])

{{--
    One summary stat inside a `dl` grid: a standalone card by default, or a bordered row inside a card with `variant="field"`.
    A non-empty slot replaces the value `dd`. With `href` the label becomes a link whose hit area covers the whole stat
    (a `dl` only allows `dt` / `dd` groups, so the link cannot wrap the stat) and the stat shows the link's focus ring.
    `linkLabel` is visually hidden text appended to the label after a comma, so the link's accessible name says where
    it leads.
--}}
<div {{ $attributes->class([
    'grid min-w-0 content-start',
    'gap-2 rounded-2xl border border-line bg-surface p-4 sm:p-5' => $variant === 'card',
    'justify-items-start gap-2 rounded-xl border border-line bg-field px-4 py-3.5' => $variant === 'field',
    'relative transition hover:border-brand has-[a:focus-visible]:outline-3 has-[a:focus-visible]:outline-offset-2 has-[a:focus-visible]:outline-accent/50' => filled($href),
]) }}>
    @if($variant === 'field')
        <dt>@if($href)<a href="{{ $href }}" class="outline-hidden after:absolute after:inset-0"><x-ui.eyebrow as="span">{{ $label }}</x-ui.eyebrow>@if($linkLabel)<span class="sr-only">, {{ $linkLabel }}</span>@endif</a>@else<x-ui.eyebrow as="span">{{ $label }}</x-ui.eyebrow>@endif</dt>
    @else
        <dt class="text-xs font-black tracking-[0.12em] wrap-break-word text-muted uppercase">@if($href)<a href="{{ $href }}" class="outline-hidden after:absolute after:inset-0">{{ $label }}@if($linkLabel)<span class="sr-only">, {{ $linkLabel }}</span>@endif</a>@else{{ $label }}@endif</dt>
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
