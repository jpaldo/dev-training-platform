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
    {{-- Pre-Tailwind layout styles sit in the `legacy` layer so Tailwind utilities override them on converted views. --}}
    <style>
        @layer legacy {
        :root{--line:#293052;--muted:#9da7c6;--p:#8b5cf6;--c:#22d3ee}
        *{box-sizing:border-box}
        a{color:inherit;text-decoration:none}
        .eyebrow{color:var(--c);font-weight:900;letter-spacing:.1em;font-size:13px}
        h1{font-size:clamp(42px,7vw,76px);line-height:.95;margin:14px 0;background:linear-gradient(90deg,#fff,#a78bfa,#22d3ee);-webkit-background-clip:text;color:transparent}
        .muted{color:var(--muted);line-height:1.6}
        .card{background:#11162b;border:1px solid var(--line);border-radius:22px;padding:26px}
        .form{display:grid;gap:18px}
        .field label{display:block;font-weight:800;margin-bottom:8px}
        .field input,.field select,.field textarea,.field input[type=file]{width:100%;background:#0a0f21;color:#fff;border:1px solid var(--line);border-radius:12px;padding:13px;font:inherit;min-height:46px}
        .field textarea{min-height:110px}
        .field input:focus,.field select:focus,.field textarea:focus{outline:3px solid #22d3ee55;border-color:var(--c)}
        input[type=checkbox]{accent-color:var(--p);width:18px;height:18px}
        .btn{display:inline-block;border:0;border-radius:12px;padding:13px 18px;font-weight:900;cursor:pointer;background:linear-gradient(90deg,var(--p),#4f46e5);color:#fff}
        }
    </style>
</head>
<body class="m-0 min-h-screen bg-canvas font-sans text-slate-100 antialiased">
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
