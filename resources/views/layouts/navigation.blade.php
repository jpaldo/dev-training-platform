@php
    $navigationUser = auth()->user();
    $navigationGroups = [];

    if ($navigationUser->isCoachOrAdmin()) {
        $navigationGroups['Overview'] = [
            ['label' => 'Coach Dashboard', 'route' => 'coach.dashboard', 'active' => ['coach.dashboard']],
        ];
        $navigationGroups['People'] = [
            ['label' => 'Participants', 'route' => 'coach.participants', 'active' => ['coach.participants', 'coach.participants.*']],
            ['label' => 'Attendance', 'route' => 'coach.attendance.index', 'active' => ['coach.attendance.*']],
        ];
        $navigationGroups['Review'] = [
            ['label' => 'Reviews', 'route' => 'coach.reviews', 'active' => ['coach.reviews', 'coach.reviews.*']],
            ['label' => 'Gates', 'route' => 'coach.gates', 'active' => ['coach.gates', 'training.coach.gates.*']],
            ['label' => 'Scoring', 'route' => 'coach.scoring.index', 'active' => ['coach.scoring.*']],
            ['label' => 'Recovery Requests', 'route' => 'coach.recovery.index', 'active' => ['coach.recovery.*']],
            ['label' => 'Reports', 'route' => 'coach.reports', 'active' => ['coach.reports', 'coach.reports.*']],
        ];
        $navigationGroups['Resources'] = [
            ['label' => 'Mentor Booking Calendar', 'route' => 'coach.mentor-booking-calendar.index', 'active' => ['coach.mentor-booking-calendar.*', 'coach.mentor-availability.*']],
            ['label' => 'Reusable Asset Library', 'route' => 'training.reusable-assets.index', 'active' => ['training.reusable-assets.*']],
        ];

        if ($navigationUser->role === 'admin') {
            $navigationGroups['Admin'] = [
                ['label' => 'Admin Dashboard', 'route' => 'admin.dashboard', 'active' => ['admin.dashboard']],
                ['label' => 'Users / Participants', 'route' => 'admin.users.index', 'active' => ['admin.users.*']],
                ['label' => 'Settings', 'route' => 'admin.settings.index', 'active' => ['admin.settings.index', 'admin.settings.email', 'admin.settings.google', 'admin.settings.google.*', 'admin.settings.interview-scheduling', 'admin.settings.training']],
                ['label' => 'Google Drive', 'route' => 'admin.settings.google-drive', 'active' => ['admin.settings.google-drive']],
                ['label' => 'AI', 'route' => 'admin.settings.ai', 'active' => ['admin.settings.ai']],
            ];
        }
    } else {
        $navigationGroups['Training'] = [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => ['dashboard', 'training.weeks.*', 'training.activities.*', 'training.submission.*', 'training.gates.*', 'training.guides.*', 'training.video-recording.*']],
            ['label' => 'Daily Log', 'route' => 'daily-logs.index', 'active' => ['daily-logs.*']],
            ['label' => 'Attendance', 'route' => 'participant.attendance.index', 'active' => ['participant.attendance.*']],
            ['label' => 'Notifications', 'route' => 'participant.notifications', 'active' => ['participant.notifications'], 'count' => $participantNotificationUnreadCount ?? 0],
        ];
        $navigationGroups['Resources'] = [
            ['label' => 'Reusable Asset Library', 'route' => 'training.reusable-assets.index', 'active' => ['training.reusable-assets.*']],
        ];
    }
@endphp
<aside id="app-sidebar" aria-label="Main navigation" class="invisible fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col gap-6 overflow-y-auto border-r border-line bg-surface-sunken px-3.5 py-6 shadow-2xl shadow-black/60 transition data-open:visible data-open:translate-x-0 lg:visible lg:sticky lg:top-0 lg:h-screen lg:shrink-0 lg:translate-x-0 lg:shadow-none">
    <div class="flex items-center justify-between gap-2">
        <a class="px-2.5 py-1 text-[13px]/snug font-black tracking-[0.08em] text-slate-100" href="{{ route('home') }}">⚡ DEVELOPER TRAINING PLATFORM</a>
        <button class="cursor-pointer rounded-lg border border-line bg-raised px-2.5 py-1 text-xl/none text-white focus-visible:outline-3 focus-visible:outline-accent/50 lg:hidden" type="button" data-sidebar-close aria-label="Close menu">×</button>
    </div>
    <nav class="grid gap-5">
        @foreach($navigationGroups as $groupLabel => $items)
            <div class="grid gap-0.5">
                <div class="mb-1.5 px-2.5 text-[11px] font-black tracking-[0.12em] text-subtle uppercase">{{ $groupLabel }}</div>
                @foreach($items as $item)
                    <x-ui.sidebar-link :href="route($item['route'])" :active="request()->routeIs(...$item['active'])" :count="$item['count'] ?? 0">{{ $item['label'] }}</x-ui.sidebar-link>
                @endforeach
            </div>
        @endforeach
    </nav>
    <div class="mt-auto grid gap-1 border-t border-line pt-4">
        @if($participantDriveHeader)
            <div class="mb-2.5">
                @if($participantDriveHeader['active'])
                    <x-ui.button variant="secondary" class="w-full" disabled>SYNCING...</x-ui.button>
                @elseif($participantDriveHeader['connected'] && $participantDriveHeader['destination_selected'] && $participantDriveHeader['configuration_ready'])
                    <form method="POST" action="{{ route('google-drive.sync') }}">@csrf<x-ui.button variant="secondary" type="submit" class="w-full">SYNC TO GOOGLE DRIVE</x-ui.button></form>
                @elseif(! $participantDriveHeader['connected'])
                    <x-ui.button variant="secondary" class="w-full" :href="route('profile.show')">SET UP GOOGLE DRIVE</x-ui.button>
                @else
                    <x-ui.button variant="secondary" class="w-full" :href="route('profile.show')">CHOOSE DRIVE FOLDER</x-ui.button>
                @endif
            </div>
        @endif
        <div class="grid gap-0.5 px-2.5 pt-1 pb-2 text-sm wrap-anywhere">
            <strong class="text-slate-100">{{ $navigationUser->name }}</strong>
            <span class="text-xs text-muted">{{ ucfirst($navigationUser->role) }}</span>
        </div>
        <x-ui.sidebar-link :href="route('profile.show')" :active="request()->routeIs('profile.*')">Profile</x-ui.sidebar-link>
        <form method="POST" action="{{ route('logout') }}">@csrf<x-ui.sidebar-link>Logout</x-ui.sidebar-link></form>
    </div>
</aside>
<div class="fixed inset-0 z-40 bg-black/70 lg:hidden" data-sidebar-close data-sidebar-backdrop hidden></div>
