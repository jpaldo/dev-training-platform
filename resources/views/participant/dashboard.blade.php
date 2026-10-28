@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6">
        @if($errors->has('google_drive_setup'))
            <x-ui.alert tone="error" role="alert" class="grid justify-items-start gap-2">
                <strong>Google Drive setup required</strong>
                <span>{{ $errors->first('google_drive_setup') }}</span>
                <x-ui.button variant="secondary" :href="route('profile.show')">{{ auth()->user()->googleDriveConnection ? 'Choose Export Folder' : 'Connect Google Drive' }}</x-ui.button>
            </x-ui.alert>
        @endif

        <header class="grid gap-2">
            <x-ui.eyebrow>PARTICIPANT DASHBOARD</x-ui.eyebrow>
            <x-ui.page-title>Welcome, {{ $user->first_name ?: $user->name }}</x-ui.page-title>
            <p class="text-muted">Keep track of what needs attention and open your curricula.</p>
        </header>

        <x-ui.card class="flex flex-wrap gap-x-8 gap-y-6">
            <div class="grid min-w-0 grow basis-44 content-start gap-1.5">
                <span class="text-xs font-black tracking-[0.1em] text-muted uppercase">Mentor</span>
                <span class="font-extrabold wrap-anywhere">{{ $mentorNames ?: 'Not assigned' }}</span>
            </div>
            @if($notificationSummary['next'])
                <x-ui.alert class="grid min-w-0 grow basis-64 content-start justify-items-start gap-2 p-4">
                    <span class="text-xs font-black tracking-[0.1em] text-cyan-200">NEXT ACTION</span>
                    <p class="m-0 text-muted">{{ $notificationSummary['next']->title }} — {{ $notificationSummary['next']->body }}</p>
                    @if($notificationSummary['next']->action_url)<x-ui.button :href="$notificationSummary['next']->action_url">Open Next Action</x-ui.button>@endif
                </x-ui.alert>
            @endif
        </x-ui.card>

        @if($notificationSummary['items']->isNotEmpty())
            <details class="group rounded-2xl border border-line bg-surface p-6" open data-dashboard-disclosure>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-lg focus-visible:outline-3 focus-visible:outline-offset-4 focus-visible:outline-accent/50 [&::-webkit-details-marker]:hidden" aria-controls="dashboard-missing-content" aria-expanded="true">
                    <span class="grid gap-1.5"><x-ui.eyebrow as="span">NEEDS ATTENTION</x-ui.eyebrow><span class="text-2xl font-extrabold">What's Missing</span></span>
                    <span class="text-accent transition group-open:rotate-180" aria-hidden="true">▼</span>
                </summary>
                <div id="dashboard-missing-content" class="mt-5 grid justify-items-start gap-4">
                    @foreach($notificationSummary['groups'] as $group => $items)
                        <div class="w-full">
                            <strong class="text-sm">{{ $group }}</strong>
                            <ul class="m-0 mt-2 grid list-disc gap-1 pl-5 text-sm/relaxed">
                                @foreach($items->take(5) as $item)<li>{{ $item->body }} @if($item->action_url)<a class="text-accent underline underline-offset-3" href="{{ $item->action_url }}">Open</a>@endif</li>@endforeach
                            </ul>
                        </div>
                    @endforeach
                    <x-ui.button variant="secondary" :href="route('participant.notifications')">View all notifications</x-ui.button>
                </div>
            </details>
            <script>
                (() => {
                    document.querySelectorAll('[data-dashboard-disclosure]').forEach((disclosure) => {
                        const summary = disclosure.querySelector(':scope > summary');
                        if (!summary) return;
                        const sync = () => summary.setAttribute('aria-expanded', disclosure.open ? 'true' : 'false');
                        disclosure.addEventListener('toggle', sync);
                        sync();
                    });
                })();
            </script>
        @endif

        <x-program-list id="my-programs" title="My curricula" :programs="$myPrograms" empty="You are not in a curriculum yet." />
        <x-program-list id="available-programs" title="Available curricula" :programs="$availablePrograms" empty="No curricula are open to join right now." />
        <x-program-list id="past-programs" title="Past curricula" :programs="$pastPrograms" empty="No past curricula yet." />
    </div>
@endsection
