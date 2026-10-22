@extends('layouts.app')

@section('content')
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>PARTICIPANT NOTIFICATIONS</x-ui.eyebrow>
            <x-ui.page-title>Notifications</x-ui.page-title>
            <p class="m-0 leading-relaxed text-muted">Your current training reminders and important updates.</p>
        </header>

        <x-ui.main-action id="notifications-main-action">
            <form method="POST" action="{{ $readAllUrl }}" class="m-0">
                @csrf
                <x-ui.button type="submit">Mark All as Read</x-ui.button>
            </form>
        </x-ui.main-action>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-label="Notifications">
                <nav aria-label="Filter notifications">
                    <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
                        @foreach($filters as $filterLink)
                            <li>
                                <a href="{{ $filterLink['url'] }}" aria-current="{{ $filterLink['is_active'] ? 'page' : 'false' }}" @class([
                                    'inline-flex items-center rounded-full border px-3.5 py-1.5 text-sm font-bold no-underline transition focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50',
                                    'border-accent bg-brand-soft text-white' => $filterLink['is_active'],
                                    'border-line text-muted hover:border-brand hover:text-slate-100' => ! $filterLink['is_active'],
                                ])>{{ $filterLink['label'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>

                @if($notifications->isNotEmpty())
                    <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0">
                        @foreach($notifications as $notification)
                            <li data-notification-row @class([
                                'grid min-w-0 grid-cols-1 gap-2 rounded-xl border px-4 py-3.5',
                                'border-brand/60 bg-raised' => ! $notification['is_read'],
                                'border-line bg-field' => $notification['is_read'],
                            ])>
                                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                                        <strong class="wrap-break-word">{{ $notification['title'] }}</strong>
                                        @if(! $notification['is_read'])
                                            <x-ui.badge tone="brand">Unread</x-ui.badge>
                                        @endif
                                    </div>
                                    <time datetime="{{ $notification['created_at'] }}" class="text-sm text-muted">{{ $notification['created_at_label'] }}</time>
                                </div>
                                <p class="m-0 text-sm/relaxed wrap-anywhere text-muted">{{ $notification['body'] }}</p>
                                @if($notification['action_url'] || ! $notification['is_read'])
                                    <div class="flex flex-wrap items-center gap-2 pt-1">
                                        @if($notification['action_url'])
                                            <x-ui.button variant="secondary" :href="$notification['action_url']">{{ $notification['action_label'] }}</x-ui.button>
                                        @endif
                                        @if(! $notification['is_read'])
                                            <form method="POST" action="{{ $notification['read_url'] }}" class="m-0">
                                                @csrf
                                                <x-ui.button variant="secondary" type="submit">Mark as Read</x-ui.button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="m-0 text-muted">No notifications right now.</p>
                @endif
                @if($notifications->hasPages())
                    <div>{{ $notifications->links() }}</div>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
