<x-ui.alert :tone="$notice['tone']" class="grid grid-cols-1 gap-2" data-sessions-notice="{{ $notice['tone'] }}">
    <p>{{ $notice['message'] }}</p>
    @if($notice['sessions'] !== [])
        <ul class="grid grid-cols-1 gap-1.5">
            @foreach($notice['sessions'] as $session)
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1" data-notice-session>
                    <a href="{{ $session['url'] }}" class="font-bold underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $session['title'] }}</a>
                    <span class="whitespace-nowrap">{{ $session['dates'] }}</span>
                    <x-ui.badge :tone="$session['status_tone']">{{ $session['status_label'] }}</x-ui.badge>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.alert>
