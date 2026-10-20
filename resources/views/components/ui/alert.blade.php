@props(['tone' => 'info'])

<div {{ $attributes->class([
    'rounded-xl border px-4 py-3 text-sm/relaxed',
    'border-cyan-800 bg-cyan-950/60 text-cyan-100' => $tone === 'info',
    'border-emerald-700 bg-emerald-950/70 text-emerald-200' => $tone === 'success',
    'border-rose-900 bg-rose-950/70 text-rose-200' => $tone === 'error',
]) }}>{{ $slot }}</div>
