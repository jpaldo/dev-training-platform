{{--
    A collapsed "Show preview" disclosure for a Google Drive file. Its iframe has no `src` until the disclosure is first
    expanded (`initializeResourcePreviews` in resources/js/app.js sets it from `data-preview-src`), so a collapsed
    preview loads nothing. The slot is shown under the iframe while it is expanded.

    $previewUrl: the file's embeddable Drive preview URL, e.g. https://drive.google.com/file/d/{id}/preview
    $title: what is previewed, for screen readers and the iframe title
--}}
@props(['previewUrl', 'title'])

<details {{ $attributes->class(['group min-w-0']) }} data-resource-preview>
    <summary class="inline-block cursor-pointer list-none rounded-lg text-sm font-extrabold text-accent underline-offset-3 hover:underline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50 [&::-webkit-details-marker]:hidden">
        <span aria-hidden="true" class="inline-block transition group-open:rotate-90">▸</span>
        <span class="group-open:hidden">Show preview</span><span class="hidden group-open:inline">Hide preview</span>
        <span class="sr-only">of {{ $title }}</span>
    </summary>
    <div class="mt-3 grid min-w-0 grid-cols-1 gap-2">
        <iframe
            data-preview-src="{{ $previewUrl }}"
            title="Preview of {{ $title }}"
            allow="autoplay; fullscreen"
            allowfullscreen
            class="aspect-4/3 max-h-[75vh] w-full rounded-xl border border-line bg-white"
        ></iframe>
        {{ $slot }}
    </div>
</details>
