{{--
    What a week tab of the session page shows instead of the week's contents while the week is not open for the
    participant: the reason (`SessionWeekState::lockedMessage()`), e.g. "Opens on Mon 16 Nov" or "Complete your Week 1
    deliverables to unlock". The same message is the 403 of the week's pages.
--}}
<x-ui.card padding="p-5" class="grid grid-cols-1 gap-3" data-locked-week>
    <p class="flex items-start gap-3 font-extrabold">
        <span aria-hidden="true">🔒</span>
        <span><span class="sr-only">Locked: </span>{{ $message }}</span>
    </p>
</x-ui.card>
