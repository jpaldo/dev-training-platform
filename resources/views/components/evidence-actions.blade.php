@props(['evidence', 'viewUrl' => null, 'downloadUrl' => null, 'previewUrl' => null, 'modalId' => null])

@php
    $preview = \App\Support\EvidencePreview::for($evidence);
    $viewUrl ??= route('training.evidence.view', $evidence);
    $downloadUrl ??= route('training.evidence.download', $evidence);
    $previewUrl ??= route('training.evidence.preview', $evidence);
    $modalId ??= 'evidence-preview-'.$evidence->id;
@endphp

@if($preview->isPreviewable())
    <x-ui.button variant="secondary" data-evidence-preview-open data-evidence-preview-dialog="{{ $modalId }}">View</x-ui.button>
    <dialog
        id="{{ $modalId }}"
        aria-labelledby="{{ $modalId }}-title"
        data-evidence-preview-kind="{{ $preview->kind }}"
        class="m-auto max-h-[90vh] w-[min(95vw,1200px)] max-w-none rounded-[18px] border border-line bg-surface p-0 text-base text-slate-100 backdrop:bg-[rgb(3_5_15/0.8)] max-md:w-[calc(100vw-20px)]"
    >
        <div class="grid max-h-[90vh] grid-cols-1 gap-3.5 overflow-y-auto p-5.5 max-md:p-4">
            <div class="flex items-start justify-between gap-4">
                <div class="grid min-w-0 gap-1">
                    <h2 id="{{ $modalId }}-title" class="m-0 text-xl font-extrabold wrap-anywhere">{{ $preview->filename }}</h2>
                    <p class="m-0 text-sm text-muted">{{ $preview->formatLabel }}</p>
                </div>
                <button
                    type="button"
                    data-evidence-preview-close
                    aria-label="Close evidence preview"
                    class="shrink-0 cursor-pointer rounded-[10px] border border-line bg-raised px-3 py-2 font-sans text-xl leading-none text-white hover:border-brand focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-accent/50"
                >×</button>
            </div>
            <p class="m-0 text-muted" data-evidence-preview-loading role="status">Loading preview…</p>
            <x-ui.alert tone="error" data-evidence-preview-error role="alert" hidden>Unable to preview this evidence.</x-ui.alert>
            <div class="grid min-h-45 place-items-center overflow-auto">
                @if($preview->kind === \App\Support\EvidencePreview::IMAGE)
                    <img
                        class="h-auto max-h-[76vh] w-auto max-w-full rounded-xl object-contain"
                        alt="{{ $preview->filename }}"
                        data-evidence-preview-image
                        data-preview-src="{{ $viewUrl }}"
                        hidden
                    >
                @elseif($preview->kind === \App\Support\EvidencePreview::PDF)
                    <iframe
                        class="h-[min(72vh,760px)] w-full rounded-xl border border-line bg-white"
                        title="{{ $preview->filename }}"
                        data-evidence-preview-frame
                        data-preview-src="{{ $viewUrl }}"
                        hidden
                    ></iframe>
                    <p class="m-0 text-muted" data-evidence-preview-fallback hidden>PDF preview is not available in this browser.</p>
                @elseif($preview->kind === \App\Support\EvidencePreview::VIDEO)
                    <video
                        class="max-h-[72vh] w-full rounded-xl bg-black"
                        controls
                        preload="metadata"
                        playsinline
                        data-evidence-preview-video
                        data-preview-src="{{ $viewUrl }}"
                        hidden
                    ><source type="{{ $preview->videoSourceType }}"></video>
                @else
                    <pre
                        class="m-0 max-h-[72vh] w-full overflow-auto rounded-xl border border-line bg-field p-4 text-sm whitespace-pre-wrap wrap-anywhere"
                        data-evidence-preview-text
                        data-preview-src="{{ $previewUrl }}"
                        hidden
                    ></pre>
                    <div class="max-h-[72vh] w-full overflow-auto rounded-xl border border-line" data-evidence-preview-table-wrap hidden>
                        <table class="w-full min-w-155 border-collapse bg-field text-sm" data-evidence-preview-table></table>
                    </div>
                @endif
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" data-evidence-preview-close>Close</x-ui.button>
                <x-ui.button variant="secondary" :href="$downloadUrl">Download</x-ui.button>
            </div>
        </div>
    </dialog>
@else
    <x-ui.button variant="secondary" :href="$viewUrl">View</x-ui.button>
    <x-ui.button variant="secondary" :href="$downloadUrl">Download</x-ui.button>
@endif
