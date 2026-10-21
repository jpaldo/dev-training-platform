<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title ?? 'Developer Training Platform' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @isset($metaDescription)
        <meta name="description" content="{{ $metaDescription }}">
    @endisset
</head>
<body class="m-0 min-h-screen bg-canvas font-sans leading-[normal] text-slate-100 antialiased">
    @auth
        <div class="flex min-h-screen">
            @include('layouts.navigation')
            <div class="min-w-0 flex-1">
                <header class="sticky top-0 z-30 flex items-center gap-3 border-b border-line bg-canvas/95 px-4 py-3 backdrop-blur lg:hidden">
                    <button class="cursor-pointer rounded-lg border border-line bg-raised px-3 py-1.5 font-sans text-sm font-extrabold text-white focus-visible:outline-3 focus-visible:outline-accent/50" type="button" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="false">☰ Menu</button>
                    <a class="min-w-0 rounded text-xs font-black tracking-[0.08em] focus-visible:outline-3 focus-visible:outline-accent/50" href="{{ route('home') }}">⚡ DEVELOPER TRAINING PLATFORM</a>
                </header>
                <div class="px-[clamp(1rem,3vw,3rem)] py-6">
                    <main>
                        @yield('content')
                    </main>
                    @include('layouts.footer')
                </div>
            </div>
        </div>
    @else
        <div class="mx-auto max-w-280 px-5.5 py-6">
            <header class="mb-9 flex flex-wrap items-start justify-between gap-3 sm:items-center">
                <a class="rounded font-black tracking-[0.08em] focus-visible:outline-3 focus-visible:outline-accent/50" href="{{ route('home') }}">⚡ DEVELOPER TRAINING PLATFORM</a>
                <nav class="flex w-full flex-wrap items-center gap-2 sm:w-auto" aria-label="Account">
                    <a class="rounded-full border border-line px-2.75 py-1.75 text-xs text-muted hover:border-brand hover:text-slate-100 focus-visible:outline-3 focus-visible:outline-accent/50" href="{{ route('login') }}">Login</a>
                    <a class="rounded-full border border-line px-2.75 py-1.75 text-xs text-muted hover:border-brand hover:text-slate-100 focus-visible:outline-3 focus-visible:outline-accent/50" href="{{ route('register') }}">Register</a>
                </nav>
            </header>
            <main>
                @yield('content')
            </main>
            @include('layouts.footer')
        </div>
    @endauth
</body>
</html>
