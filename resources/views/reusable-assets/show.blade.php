@extends('layouts.app')

@section('content')
    @php
        $sectionTitleClasses = 'm-0 text-xl font-extrabold';
        $termClasses = 'text-xs font-bold tracking-[0.08em] text-subtle uppercase';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>REUSABLE AI ASSET</x-ui.eyebrow>
            <x-ui.page-title>{{ $assetName }}</x-ui.page-title>
        </header>

        <x-ui.card as="section" aria-label="Asset summary">
            <dl class="m-0 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="grid min-w-0 content-start gap-1">
                    <dt class="{{ $termClasses }}">Owner</dt>
                    <dd class="m-0 font-bold wrap-anywhere">{{ $owner }}</dd>
                </div>
                <div class="grid min-w-0 content-start gap-1">
                    <dt class="{{ $termClasses }}">Asset Type</dt>
                    <dd class="m-0 font-bold wrap-anywhere">{{ $assetType }}</dd>
                </div>
                <div class="grid min-w-0 content-start gap-1 sm:col-span-2">
                    <dt class="{{ $termClasses }}">Purpose</dt>
                    <dd class="m-0 leading-relaxed wrap-break-word">{{ $purpose }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-4" aria-labelledby="asset-details-title">
            <h2 id="asset-details-title" class="{{ $sectionTitleClasses }}">About this asset</h2>
            @if($detailRows !== [])
                <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0">
                    @foreach($detailRows as $detailRow)
                        <li class="grid min-w-0 grid-cols-1 gap-2 rounded-xl border border-line bg-field px-4 py-3.5" data-asset-detail>
                            <strong>{{ $detailRow['label'] }}</strong>
                            <div class="min-w-0 rounded-xl bg-surface-sunken p-4">
                                <x-formatted-text :text="$detailRow['text']" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="m-0 text-sm/relaxed text-muted">No asset details were provided.</p>
            @endif
        </x-ui.card>

        <x-ui.card as="section" class="grid grid-cols-1 gap-3" aria-labelledby="asset-file-title">
            <h2 id="asset-file-title" class="{{ $sectionTitleClasses }}">Reusable AI Asset</h2>
            <p class="m-0 wrap-anywhere"><strong>{{ $filename }}</strong></p>
            @if($isPreviewUnavailable)
                <p class="m-0 text-sm/relaxed text-muted">Preview unavailable for this archive file type.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button variant="secondary" :href="$downloadUrl">Download</x-ui.button>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <x-evidence-actions :evidence="$asset" :view-url="$viewUrl" :download-url="$downloadUrl" :preview-url="$previewUrl" :modal-id="$modalId" />
                </div>
            @endif
        </x-ui.card>

        <div class="flex flex-wrap gap-3">
            <x-ui.button variant="secondary" :href="$libraryUrl">Back to Library</x-ui.button>
        </div>
    </div>
@endsection
