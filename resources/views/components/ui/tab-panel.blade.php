@props(['name', 'selected', 'idPrefix'])

<section {{ $attributes->class(['rounded-2xl focus-visible:outline-3 focus-visible:outline-offset-4 focus-visible:outline-accent/50']) }} role="tabpanel" id="{{ $idPrefix }}-panel-{{ $name }}" aria-labelledby="{{ $idPrefix }}-tab-{{ $name }}" tabindex="0" @if($name !== $selected) hidden @endif>
    {{ $slot }}
</section>
