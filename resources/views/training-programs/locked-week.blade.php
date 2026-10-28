{{--
    What a week tab of the curriculum page shows instead of the week's contents while the week is not open for the
    participant: the reason (`ProgramWeekState::lockedMessage()`), e.g. "Opens on Mon 16 Nov", "Not scheduled yet",
    "Complete your Week 1 deliverables to unlock" or "Waiting for your mentor to verify Week 1". The same message is
    the 403 of the week's pages. While an incomplete review locks the week, the review's missing checklist items follow, each linking to the
    tab of the reviewed week.
--}}
<x-ui.card padding="p-5" class="grid grid-cols-1 gap-3" data-locked-week>
    <p class="flex items-start gap-3 font-extrabold">
        <span aria-hidden="true">🔒</span>
        <span><span class="sr-only">Locked: </span>{{ $message }}</span>
    </p>
    @if($missingItems !== [])
        <div class="grid grid-cols-1 gap-2 text-sm/relaxed">
            <p class="text-muted">Not met in your Week {{ $lockedByWeekNumber }} review:</p>
            <ul class="list-disc space-y-1 pl-5" data-missing-items>
                @foreach($missingItems as $title)
                    <li>
                        <a href="{{ $missingItemsUrl }}" class="font-bold wrap-break-word text-accent underline underline-offset-3 focus-visible:rounded focus-visible:outline-3 focus-visible:outline-accent/50">{{ $title }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-ui.card>
