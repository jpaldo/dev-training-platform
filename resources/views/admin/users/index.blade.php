@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white placeholder:text-subtle focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
        $headerCellClasses = 'border-b border-line px-3.5 py-3 text-left align-bottom text-xs font-bold tracking-[0.06em] text-muted uppercase max-sm:px-2.5';
        $cellClasses = 'border-b border-line px-3.5 py-3.5 text-left align-middle max-sm:px-2.5 max-sm:py-3';
    @endphp

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>ADMIN · USERS</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">User Management</h1>
            <p class="m-0 leading-relaxed text-muted">Access Type determines each user's role in the training platform.</p>
        </header>

        {{-- The single main action stays in view: in the page header on desktop, pinned to the bottom of the screen on mobile. --}}
        <div id="admin-users-main-action" class="z-20 flex flex-wrap items-center gap-3 max-lg:sticky max-lg:bottom-3 max-lg:order-last max-lg:rounded-2xl max-lg:border max-lg:border-line max-lg:bg-surface/95 max-lg:p-3 max-lg:shadow-2xl max-lg:shadow-black/60 max-lg:backdrop-blur lg:col-start-2 lg:row-start-1 lg:self-start lg:justify-end">
            <x-ui.button :href="$createUrl">+ Add User</x-ui.button>
        </div>

        <div class="grid min-w-0 grid-cols-1 gap-6 lg:col-span-2">
            <nav aria-label="Admin settings">
                <ul class="m-0 flex list-none flex-wrap gap-3 p-0">
                    @foreach($settingsLinks as $link)
                        <li>
                            <x-ui.button variant="secondary" :href="$link['url']">{{ $link['label'] }}</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            </nav>

            @if(session('status'))
                <x-ui.alert tone="success" role="status">{{ session('status') }}</x-ui.alert>
            @endif
            @if($errors->any())
                <x-ui.alert tone="error" role="alert">{{ $errors->first() }}</x-ui.alert>
            @endif

            @if(session('password_setup_url'))
                <x-ui.card as="section" class="grid grid-cols-1 gap-3" role="status" aria-labelledby="password-setup-title">
                    <h2 id="password-setup-title" class="m-0 text-xl font-extrabold">Password setup link</h2>
                    <p class="m-0 leading-relaxed text-muted">Share this one-time link with the new user through your approved secure channel. It expires in {{ $passwordSetupExpiresInMinutes }} minutes and is not shown again.</p>
                    <input type="text" value="{{ session('password_setup_url') }}" readonly aria-label="One-time password setup link" class="{{ $controlClasses }}">
                </x-ui.card>
            @endif

            <x-ui.card as="form" method="GET" class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:items-end xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]" aria-label="Filter users">
                <div class="grid min-w-0 gap-2">
                    <label for="user-search" class="{{ $labelClasses }}">Search</label>
                    <input id="user-search" name="search" value="{{ $search }}" placeholder="Name or email" class="{{ $controlClasses }}">
                </div>
                <div class="grid min-w-0 gap-2">
                    <label for="access-type" class="{{ $labelClasses }}">Access Type</label>
                    <select id="access-type" name="access_type" class="{{ $controlClasses }}">
                        <option value="">All</option>
                        @foreach($accessTypeOptions as $option)
                            <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid min-w-0 gap-2">
                    <label for="user-status" class="{{ $labelClasses }}">Status</label>
                    <select id="user-status" name="status" class="{{ $controlClasses }}">
                        <option value="">All</option>
                        @foreach($statusOptions as $option)
                            <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid min-w-0 gap-2">
                    <label for="user-path" class="{{ $labelClasses }}">QA Path</label>
                    <select id="user-path" name="path" class="{{ $controlClasses }}">
                        <option value="">All</option>
                        @foreach($pathOptions as $option)
                            <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <x-ui.button variant="secondary" type="submit">Filter</x-ui.button>
            </x-ui.card>

            <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="user-list-title">
                <h2 id="user-list-title" class="m-0 text-xl font-extrabold">Users</h2>
                @if($users->isNotEmpty())
                    <div class="overflow-x-auto rounded-xl focus-visible:outline-3 focus-visible:outline-accent/50" role="region" aria-labelledby="user-list-title" tabindex="0">
                        <table class="w-full min-w-220 border-collapse text-sm" aria-labelledby="user-list-title">
                            <thead>
                                <tr>
                                    <th scope="col" class="{{ $headerCellClasses }}">Name</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Email</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Access Type</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">QA Path</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Status</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Created</th>
                                    <th scope="col" class="{{ $headerCellClasses }}">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($users as $user)
                                    <tr data-user-row>
                                        <th scope="row" class="{{ $cellClasses }} font-bold wrap-break-word">{{ $user['name'] }}</th>
                                        <td class="{{ $cellClasses }} wrap-anywhere text-muted">{{ $user['email'] }}</td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap">{{ $user['access_type'] }}</td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap">{{ $user['path'] }}</td>
                                        <td class="{{ $cellClasses }}">
                                            <x-ui.badge :tone="$user['is_active'] ? 'success' : 'danger'">{{ $user['is_active'] ? 'Active' : 'Disabled' }}</x-ui.badge>
                                        </td>
                                        <td class="{{ $cellClasses }} whitespace-nowrap">{{ $user['created'] }}</td>
                                        <td class="{{ $cellClasses }}">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <x-ui.button variant="secondary" :href="$user['edit_url']">Edit</x-ui.button>
                                                @if($user['disable_url'])
                                                    <form method="POST" action="{{ $user['disable_url'] }}" class="m-0" onsubmit="return confirm('Disable this account? Historical training records will be preserved.')">
                                                        @csrf
                                                        <x-ui.button variant="secondary" type="submit">Disable</x-ui.button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ $user['enable_url'] }}" class="m-0">
                                                        @csrf
                                                        <x-ui.button variant="secondary" type="submit">Enable</x-ui.button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($users->hasPages())
                        <div>{{ $users->links() }}</div>
                    @endif
                @else
                    <p class="m-0 text-muted">No users match these filters.</p>
                @endif
            </x-ui.card>

            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" :href="$attendanceUrl">View Attendance</x-ui.button>
            </div>
        </div>
    </div>
@endsection
