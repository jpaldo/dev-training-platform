@props(['breakAnywhere' => false])

<h1 {{ $attributes->except('class') }} class="{{ $attributes->class([
    'm-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black text-transparent sm:text-5xl/tight',
    'wrap-break-word' => ! $breakAnywhere,
    'wrap-anywhere' => $breakAnywhere,
])->get('class') }}">{{ $slot }}</h1>
