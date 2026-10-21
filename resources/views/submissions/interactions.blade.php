<div class="{{ $sectionHeadingClasses }}">
    <x-ui.eyebrow as="span">PROMPT / OUTPUT HISTORY</x-ui.eyebrow>
    <p class="{{ $hintClasses }}">Keep each prompt paired with the AI output it produced. Follow-up prompts are optional.</p>
</div>
<div class="grid grid-cols-1 gap-3" data-interactions-list>
    @foreach(old('interactions', $rows) as $sequence => $interaction)
        <div class="{{ $rowClasses }} justify-items-start gap-3" data-interaction-row>
            <strong data-interaction-title>Prompt {{ $sequence + 1 }}</strong>
            <div class="grid w-full gap-2">
                <label for="interaction-{{ $sequence }}-prompt" class="{{ $labelClasses }}" data-interaction-label>Prompt {{ $sequence + 1 }}</label>
                <textarea id="interaction-{{ $sequence }}-prompt" name="interactions[{{ $sequence }}][prompt]" rows="4" class="{{ $textareaClasses }}">{{ $interaction['prompt'] ?? '' }}</textarea>
            </div>
            <div class="grid w-full gap-2">
                <label for="interaction-{{ $sequence }}-output" class="{{ $labelClasses }}" data-interaction-label>AI Output {{ $sequence + 1 }}</label>
                <textarea id="interaction-{{ $sequence }}-output" name="interactions[{{ $sequence }}][output]" rows="6" class="{{ $textareaClasses }}">{{ $interaction['output'] ?? '' }}</textarea>
            </div>
            @if($sequence > 0)
                <x-ui.button variant="secondary" data-remove-interaction>Remove follow-up</x-ui.button>
            @endif
        </div>
    @endforeach
</div>
<template data-interaction-template>
    <div class="{{ $rowClasses }} justify-items-start gap-3" data-interaction-row>
        <strong data-interaction-title></strong>
        <div class="grid w-full gap-2">
            <label class="{{ $labelClasses }}" data-interaction-label>Follow-up prompt</label>
            <textarea rows="4" class="{{ $textareaClasses }}"></textarea>
        </div>
        <div class="grid w-full gap-2">
            <label class="{{ $labelClasses }}" data-interaction-label>AI output</label>
            <textarea rows="6" class="{{ $textareaClasses }}"></textarea>
        </div>
        <x-ui.button variant="secondary" data-remove-interaction>Remove follow-up</x-ui.button>
    </div>
</template>
<x-ui.button variant="secondary" class="justify-self-start" data-add-interaction>+ Add Follow-Up Prompt</x-ui.button>
<script>
    (() => {
        const list = document.querySelector('[data-interactions-list]');
        const add = document.querySelector('[data-add-interaction]');
        const template = document.querySelector('[data-interaction-template]');
        if (!list || !add || !template) return;
        const renumber = () => list.querySelectorAll('[data-interaction-row]').forEach((row, index) => {
            row.querySelector('[data-interaction-title]').textContent = `Prompt ${index + 1}`;
            const labels = row.querySelectorAll('[data-interaction-label]');
            row.querySelectorAll('textarea').forEach((field, fieldIndex) => {
                const part = fieldIndex === 0 ? 'prompt' : 'output';
                field.name = `interactions[${index}][${part}]`;
                field.id = `interaction-${index}-${part}`;
                labels[fieldIndex]?.setAttribute('for', field.id);
            });
        });
        const bind = button => button.addEventListener('click', () => {
            const row = button.closest('[data-interaction-row]');
            row?.remove();
            renumber();
        });
        list.querySelectorAll('[data-remove-interaction]').forEach(bind);
        add.addEventListener('click', () => {
            const row = template.content.firstElementChild.cloneNode(true);
            list.appendChild(row);
            bind(row.querySelector('[data-remove-interaction]'));
            renumber();
        });
        renumber();
    })();
</script>
