@extends('layouts.app')

@section('content')
<div class="eyebrow">TRAINING COMMUNITY</div>
<h1>Reusable AI Asset Library</h1>
<p class="muted">Explore reusable AI assets created and validated by participants during Week 4.</p>

<form class="card" method="GET" style="display:grid;grid-template-columns:2fr 1fr auto;gap:12px;align-items:end;margin:20px 0">
    <div class="field"><label for="asset-search">Search reusable assets</label><input id="asset-search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search by asset, purpose, or owner"></div>
    <div class="field"><label for="asset-type">Asset type</label><select id="asset-type" name="asset_type"><option value="">All types</option>@foreach($assetTypes as $type)<option value="{{ $type }}" @selected(($filters['asset_type'] ?? '') === $type)>{{ $type }}</option>@endforeach</select></div>
    <button class="btn secondary" type="submit">Search</button>
</form>

@if($items->isEmpty())
    <div class="card"><h2>No reusable assets have been published yet.</h2><p class="muted">Approved Week 4 reusable assets will appear here.</p></div>
@else
    <div class="grid">
        @foreach($items as $item)
            @php($submission = $item['submission'])
            @php($version = $item['version'])
            @php($asset = $item['asset'])
            @php($values = $version->field_values ?? [])
            @php($extension = strtolower(pathinfo($asset->original_filename ?: '', PATHINFO_EXTENSION)))
            <article class="card">
                <div class="eyebrow">{{ $values['asset_type'] ?? 'Reusable QA Asset' }}</div>
                <h2>{{ $values['asset_name'] ?? 'Reusable AI Asset' }}</h2>
                <p><strong>Owner:</strong> {{ $submission->participant->name }}</p>
                <p class="muted">{{ $values['asset_purpose'] ?? 'Validated reusable QA asset.' }}</p>
                <p class="muted"><strong>File:</strong> {{ $asset->original_filename ?: 'Reusable asset' }}</p>
                <div class="actions">
                    <a class="btn secondary" href="{{ route('training.reusable-assets.show', $version) }}">View Details</a>
                    @if(in_array($extension, ['doc', 'xls', 'zip', 'tar'], true))
                        <span class="muted">Preview unavailable for this file type.</span>
                        <a class="btn secondary" href="{{ route('training.reusable-assets.download', $version) }}">Download</a>
                    @else
                        <x-evidence-actions :evidence="$asset" :view-url="route('training.reusable-assets.view', $version)" :download-url="route('training.reusable-assets.download', $version)" :preview-url="route('training.reusable-assets.preview', $version)" :modal-id="'reusable-asset-preview-'.$version->id" />
                    @endif
                </div>
            </article>
        @endforeach
    </div>
    <div style="margin-top:20px">{{ $items->links() }}</div>
@endif
@endsection
