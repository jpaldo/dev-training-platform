{{--
    The themed shell of every error page. It overrides Laravel's own `errors::minimal`, so the framework error views
    this folder does not replace (401, 402, 429) get the theme too. It must render without a signed-in user, a
    database or a view composer: no `layouts.app`, no Auth and no queries.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title') · Developer Training Platform</title>
    @vite(['resources/css/app.css'])
</head>
<body class="m-0 min-h-screen bg-canvas font-sans leading-[normal] text-slate-100 antialiased">
    <div class="mx-auto max-w-280 px-5.5 py-6">
        <header class="mb-9">
            <a class="rounded font-black tracking-[0.08em] focus-visible:outline-3 focus-visible:outline-accent/50" href="{{ route('home') }}">⚡ DEVELOPER TRAINING PLATFORM</a>
        </header>
        <main>
            <x-ui.card as="section" class="mx-auto grid max-w-xl grid-cols-1 justify-items-start gap-4" aria-labelledby="error-title">
                <x-ui.eyebrow>Error @yield('code')</x-ui.eyebrow>
                <x-ui.page-title id="error-title">@yield('title')</x-ui.page-title>
                <p class="m-0 leading-relaxed text-muted">@yield('message')</p>
                <x-ui.button :href="route('home')">Go to the home page</x-ui.button>
            </x-ui.card>
        </main>
        @include('layouts.footer')
    </div>
</body>
</html>
