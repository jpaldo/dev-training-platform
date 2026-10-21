@extends('layouts.app')

@section('content')
    @php
        $controlClasses = 'w-full rounded-xl border border-line bg-field px-3.5 py-3 font-sans text-sm text-white focus:border-accent focus-visible:outline-3 focus-visible:outline-accent/50';
        $labelClasses = 'text-sm font-extrabold';
    @endphp

    <div class="grid grid-cols-1 gap-6">
        <header class="grid min-w-0 gap-2">
            <x-ui.eyebrow>TRAINING COMMUNITY</x-ui.eyebrow>
            <h1 class="m-0 bg-linear-to-r from-white via-violet-400 to-accent bg-clip-text text-4xl/tight font-black wrap-break-word text-transparent sm:text-5xl/tight">Reusable AI Asset Library</h1>
            <p class="m-0 leading-relaxed text-muted">Explore reusable AI assets created and validated by participants during Week 4.</p>
        </header>

        <x-ui.card as="form" method="GET" class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] md:items-end" aria-label="Filter reusable assets">
            <div class="grid min-w-0 gap-2">
                <label for="asset-search" class="{{ $labelClasses }}">Search reusable assets</label>
                <input id="asset-search" name="search" value="{{ $search }}" placeholder="Search by asset, purpose, or owner" class="{{ $controlClasses }}">
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="asset-type" class="{{ $labelClasses }}">Asset type</label>
                <select id="asset-type" name="asset_type" class="{{ $controlClasses }}">
                    <option value="">All types</option>
                    @foreach($assetTypeOptions as $option)
                        <option value="{{ $option['value'] }}" @selected($option['is_selected'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <x-ui.button variant="secondary" type="submit">Search</x-ui.button>
        </x-ui.card>

        @if($items->isEmpty())
            <x-ui.card as="section" class="grid grid-cols-1 gap-2" aria-labelledby="no-assets-title">
                <h2 id="no-assets-title" class="m-0 text-xl font-extrabold">No reusable assets have been published yet.</h2>
                <p class="m-0 leading-relaxed text-muted">Approved Week 4 reusable assets will appear here.</p>
            </x-ui.card>
        @else
            <section class="grid grid-cols-1 gap-4" aria-label="Reusable assets">
                <ul class="m-0 grid list-none grid-cols-1 gap-4 p-0 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($items as $item)
                        <li class="min-w-0" data-reusable-asset>
                            <x-ui.card as="article" class="flex h-full flex-col gap-3">
                                <x-ui.eyebrow class="wrap-anywhere">{{ $item['asset_type'] }}</x-ui.eyebrow>
                                <h2 class="m-0 text-xl font-extrabold wrap-break-word">{{ $item['asset_name'] }}</h2>
                                <p class="m-0"><strong>Owner:</strong> {{ $item['owner'] }}</p>
                                <p class="m-0 leading-relaxed text-muted">{{ $item['purpose'] }}</p>
                                <p class="m-0 text-sm text-muted wrap-anywhere"><strong>File:</strong> {{ $item['filename'] }}</p>
                                <div class="mt-auto flex flex-wrap items-center gap-3 pt-1">
                                    <x-ui.button variant="secondary" :href="$item['details_url']">View Details</x-ui.button>
                                    @if($item['is_preview_unavailable'])
                                        <span class="text-sm text-muted">Preview unavailable for this file type.</span>
                                        <x-ui.button variant="secondary" :href="$item['download_url']">Download</x-ui.button>
                                    @else
                                        <x-evidence-actions :evidence="$item['asset']" :view-url="$item['view_url']" :download-url="$item['download_url']" :preview-url="$item['preview_url']" :modal-id="$item['modal_id']" />
                                    @endif
                                </div>
                            </x-ui.card>
                        </li>
                    @endforeach
                </ul>
                @if($items->hasPages())
                    <div>{{ $items->links() }}</div>
                @endif
            </section>
        @endif
    </div>
@endsection
