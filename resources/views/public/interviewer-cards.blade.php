{{-- The interviewer cards of one public interview tab: each card's booking link opens the interviewer's external scheduling page. --}}
@if($interviewers === [])
    <p class="m-0 rounded-xl border border-dashed border-line p-5 text-muted">{{ $emptyMessage }}</p>
@else
    <ul class="m-0 grid list-none grid-cols-1 gap-4.5 p-0 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($interviewers as $interviewer)
            <li class="grid min-w-0">
                <x-ui.card as="article" class="grid content-start justify-items-start gap-2">
                    <h2 class="m-0 text-xl font-extrabold wrap-break-word">{{ $interviewer['fullName'] }}</h2>
                    <p class="m-0 leading-relaxed text-muted">{{ $interviewerRole }}</p>
                    <x-ui.button variant="secondary" href="{{ $interviewer['bookingUrl'] }}" target="_blank" rel="noopener noreferrer" class="mt-2">{{ $actionLabel }}</x-ui.button>
                </x-ui.card>
            </li>
        @endforeach
    </ul>
@endif
