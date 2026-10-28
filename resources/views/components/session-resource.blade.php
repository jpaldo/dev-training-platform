{{--
    One curriculum resource: its title, kind badge and host, and a link that opens the URL as entered in a new
    tab. A Google Drive file also gets the lazily loaded `x-drive-preview`, so collapsed rows load nothing. A resource
    that has not opened for the participant yet shows only its title and its `locked_message` ("Opens on Mon 16 Nov");
    its row data carries no URL. The slot holds the row's actions (the admin's Rename and Remove).

    $resource: array{title: string, locked_message: ?string, kind_label: ?string, host: ?string, open_url: ?string, open_label: ?string, preview_url: ?string}
--}}
@props(['resource'])

<div {{ $attributes->class(['grid min-w-0 grid-cols-1 gap-3 rounded-xl border border-line bg-surface-sunken p-4']) }} data-resource-row>
    <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
        <div class="grid min-w-0 gap-1">
            <div class="flex flex-wrap items-center gap-2">
                <p class="font-bold wrap-break-word">{{ $resource['title'] }}</p>
                @if($resource['kind_label'])
                    <x-ui.badge>{{ $resource['kind_label'] }}</x-ui.badge>
                @endif
            </div>
            @if($resource['locked_message'])
                <p class="flex items-start gap-2 text-sm text-muted" data-resource-locked>
                    <span aria-hidden="true">🔒</span>
                    <span><span class="sr-only">Locked: </span>{{ $resource['locked_message'] }}</span>
                </p>
            @else
                <p class="text-sm wrap-break-word text-muted">{{ $resource['host'] }}</p>
            @endif
        </div>

        @if($resource['open_url'] || $slot->isNotEmpty())
            <div class="flex flex-wrap items-start gap-2">
                @if($resource['open_url'])
                    <x-ui.button variant="secondary" :href="$resource['open_url']" target="_blank" rel="noopener noreferrer">{{ $resource['open_label'] }}<span class="sr-only">: {{ $resource['title'] }} (opens in a new tab)</span></x-ui.button>
                @endif
                {{ $slot }}
            </div>
        @endif
    </div>

    @if($resource['preview_url'])
        <x-drive-preview :preview-url="$resource['preview_url']" :title="$resource['title']">
            <p class="text-sm/relaxed text-muted">Preview not loading? Open it in Drive. If Drive says you need access, ask an admin to share the file.</p>
        </x-drive-preview>
    @endif
</div>
