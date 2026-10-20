@props(['tone' => 'neutral'])

<span {{ $attributes->class([
    'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-bold',
    'border-line text-muted' => $tone === 'neutral',
    'border-brand text-violet-200' => $tone === 'brand',
    'border-accent text-cyan-200' => $tone === 'accent',
    'border-emerald-400 text-emerald-200' => $tone === 'success',
    'border-amber-500 text-amber-200' => $tone === 'warning',
    'border-danger text-rose-200' => $tone === 'danger',
]) }}>{{ $slot }}</span>
