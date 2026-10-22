<aside id="app-sidebar" aria-label="Main navigation" class="invisible fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col gap-6 overflow-y-auto border-r border-line bg-surface-sunken px-3.5 py-6 shadow-2xl shadow-black/60 data-sliding:transition data-open:visible data-open:translate-x-0 lg:visible lg:sticky lg:top-0 lg:h-screen lg:shrink-0 lg:translate-x-0 lg:shadow-none">
    <div class="flex items-center justify-between gap-2">
        <a class="rounded px-2.5 py-1 text-[13px]/snug font-black tracking-[0.08em] text-slate-100 focus-visible:outline-3 focus-visible:outline-accent/50" href="{{ route('home') }}">⚡ DEVELOPER TRAINING PLATFORM</a>
        <button class="cursor-pointer rounded-lg border border-line bg-raised px-2.5 py-1 text-xl/none text-white focus-visible:outline-3 focus-visible:outline-accent/50 lg:hidden" type="button" data-sidebar-close aria-label="Close menu">×</button>
    </div>

    <nav class="-mx-1 grid min-h-32 flex-1 grid-cols-1 content-start gap-5 overflow-y-auto overscroll-contain px-1 pb-1" aria-label="Primary" data-sidebar-navigation>
        @foreach($sidebarNavigation['groups'] as $group)
            <div class="grid grid-cols-1 gap-0.5" role="group" aria-labelledby="sidebar-group-{{ $loop->index }}">
                <div id="sidebar-group-{{ $loop->index }}" class="mb-1.5 px-2.5 text-[11px] font-black tracking-[0.12em] text-subtle uppercase">{{ $group['label'] }}</div>
                @foreach($group['links'] as $link)
                    <x-ui.sidebar-link :href="$link['url']" :active="$link['is_active']" :count="$link['count']">{{ $link['label'] }}</x-ui.sidebar-link>
                @endforeach
            </div>
        @endforeach
    </nav>

    <div class="grid shrink-0 grid-cols-1 gap-1 border-t border-line pt-4">
        @if($participantDriveHeader)
            <div class="mb-2.5">
                @if($participantDriveHeader['active'])
                    <x-ui.button variant="secondary" class="w-full" disabled>SYNCING...</x-ui.button>
                @elseif($participantDriveHeader['connected'] && $participantDriveHeader['destination_selected'] && $participantDriveHeader['configuration_ready'])
                    <form method="POST" action="{{ route('google-drive.sync') }}" class="m-0">
                        @csrf
                        <x-ui.button variant="secondary" type="submit" class="w-full">SYNC TO GOOGLE DRIVE</x-ui.button>
                    </form>
                @elseif(! $participantDriveHeader['connected'])
                    <x-ui.button variant="secondary" class="w-full" :href="route('profile.show')">SET UP GOOGLE DRIVE</x-ui.button>
                @else
                    <x-ui.button variant="secondary" class="w-full" :href="route('profile.show')">CHOOSE DRIVE FOLDER</x-ui.button>
                @endif
            </div>
        @endif

        <div class="grid grid-cols-1 gap-0.5 px-2.5 pt-1 pb-2 text-sm wrap-anywhere">
            <strong class="text-slate-100">{{ $sidebarNavigation['user_name'] }}</strong>
            <span class="text-xs text-muted">{{ $sidebarNavigation['role_label'] }}</span>
        </div>
        <x-ui.sidebar-link :href="$sidebarNavigation['profile']['url']" :active="$sidebarNavigation['profile']['is_active']">Profile</x-ui.sidebar-link>
        <form method="POST" action="{{ route('logout') }}" class="m-0">
            @csrf
            <x-ui.sidebar-link>Logout</x-ui.sidebar-link>
        </form>
    </div>
</aside>
<div class="fixed inset-0 z-40 bg-black/70 lg:hidden" data-sidebar-close data-sidebar-backdrop hidden></div>
