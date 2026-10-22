@props([
    'id',
    'name',
    'value' => '',
    'required' => false,
    'rows' => 8,
    'maxlength' => null,
    'placeholder' => null,
])

@php
    $modeButtonClasses = 'cursor-pointer rounded-lg border-0 bg-transparent px-3 py-1.5 font-sans text-sm font-extrabold text-muted transition hover:text-slate-100 focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50 aria-pressed:bg-brand-soft aria-pressed:text-white';
@endphp

<div class="grid min-w-0 grid-cols-1 gap-2" data-markdown-field data-preview-url="{{ route('markdown.preview') }}">
    <div class="flex w-fit gap-1 rounded-xl border border-line bg-field p-0.5" role="group" aria-label="Write or preview">
        <button type="button" class="{{ $modeButtonClasses }}" data-markdown-mode="write" aria-pressed="true">Write</button>
        <button type="button" class="{{ $modeButtonClasses }}" data-markdown-mode="preview" aria-pressed="false">Preview</button>
    </div>
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" data-markdown-source data-markdown-paste @required($required) @if($maxlength) maxlength="{{ $maxlength }}" @endif @if($placeholder) placeholder="{{ $placeholder }}" @endif {{ $attributes }}>{{ $value }}</textarea>
    <div class="markdown min-h-28 min-w-0 rounded-xl border border-line bg-field px-3.5 py-3" data-markdown-preview hidden></div>
    @if($maxlength)
        <span class="text-sm text-muted" data-char-count>0 / {{ number_format($maxlength) }} characters</span>
    @endif
</div>
