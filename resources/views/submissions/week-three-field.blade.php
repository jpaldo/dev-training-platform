<div class="grid min-w-0 gap-2">
    <label for="{{ $field['id'] }}" class="{{ $labelClasses }}">{{ $field['label'] }}{{ $field['is_required'] ? ' *' : '' }}</label>
    @if($field['options'] !== null)
        <select id="{{ $field['id'] }}" name="{{ $field['name'] }}" class="{{ $controlClasses }}">
            <option value="">{{ $field['placeholder'] }}</option>
            @foreach($field['options'] as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected(old($field['old_key'], $field['value']) === $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @else
        <textarea id="{{ $field['id'] }}" name="{{ $field['name'] }}" rows="{{ $field['rows'] }}" class="{{ $controlClasses }} resize-y">{{ old($field['old_key'], $field['value']) }}</textarea>
    @endif
</div>
