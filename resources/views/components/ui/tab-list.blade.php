@props(['tabs', 'selected', 'idPrefix', 'label'])

@php
    $stateIcons = ['passed' => '✓', 'current' => '●', 'locked' => '🔒'];
    $stateLabels = ['passed' => 'Passed', 'current' => 'Current week', 'locked' => 'Locked'];
@endphp

<div {{ $attributes->class(['mb-6 flex flex-wrap gap-1 border-b border-line']) }} role="tablist" aria-label="{{ $label }}">
    @foreach($tabs as $tab)
        @php($isSelected = $tab['key'] === $selected)
        <button type="button" role="tab" id="{{ $idPrefix }}-tab-{{ $tab['key'] }}" aria-controls="{{ $idPrefix }}-panel-{{ $tab['key'] }}" aria-selected="{{ $isSelected ? 'true' : 'false' }}" tabindex="{{ $isSelected ? '0' : '-1' }}" data-tab-key="{{ $tab['key'] }}"
            class="-mb-px inline-flex shrink-0 cursor-pointer items-center gap-2 border-x-0 border-t-0 border-b-2 border-transparent bg-transparent px-4 py-3 font-sans text-sm font-extrabold text-muted transition hover:text-slate-100 focus-visible:rounded-lg focus-visible:outline-3 focus-visible:-outline-offset-3 focus-visible:outline-accent/50 aria-selected:border-accent aria-selected:text-white">
            {{ $tab['label'] }}
            @if($tab['state'] ?? null)
                <span @class(['text-xs', 'text-emerald-400' => $tab['state'] === 'passed', 'text-accent' => $tab['state'] === 'current', 'opacity-70' => $tab['state'] === 'locked']) aria-hidden="true">{{ $stateIcons[$tab['state']] }}</span>
                <span class="sr-only">({{ $stateLabels[$tab['state']] }})</span>
            @endif
        </button>
    @endforeach
</div>
