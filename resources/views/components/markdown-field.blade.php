@props([
    'id',
    'name',
    'value' => '',
    'required' => false,
    'rows' => 8,
    'maxlength' => null,
    'placeholder' => null,
])
<div class="markdown-field">
    <div class="markdown-tabs" role="group" aria-label="Write or preview">
        <button type="button" class="markdown-tab markdown-tab-active" data-markdown-mode="write" aria-pressed="true">Write</button>
        <button type="button" class="markdown-tab" data-markdown-mode="preview" aria-pressed="false">Preview</button>
    </div>
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" data-markdown-source data-markdown-paste @required($required) @if($maxlength) maxlength="{{ $maxlength }}" @endif @if($placeholder) placeholder="{{ $placeholder }}" @endif {{ $attributes }}>{{ $value }}</textarea>
    <div class="markdown markdown-preview" data-markdown-preview hidden></div>
    @if($maxlength)<span class="muted" data-char-count>0 / {{ number_format($maxlength) }} characters</span>@endif
</div>