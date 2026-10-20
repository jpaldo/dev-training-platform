@extends('layouts.app')

@section('content')
@php($filename = $asset->original_filename ?: 'Reusable AI Asset')
@php($extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION)))
<div class="eyebrow">REUSABLE AI ASSET</div>
<h1>{{ $values['asset_name'] ?? 'Reusable AI Asset' }}</h1>
<div class="card" style="margin-top:20px">
    <p><strong>Owner</strong><br>{{ $submission->participant->name }}</p>
    <p><strong>Asset Type</strong><br>{{ $values['asset_type'] ?? 'Reusable QA Asset' }}</p>
    <p><strong>Purpose</strong><br>{{ $values['asset_purpose'] ?? 'Not provided' }}</p>
</div>
<div class="card" style="margin-top:18px">
    <h2>About this asset</h2>
    @foreach(['asset_content' => 'Asset Content', 'original_task' => 'Original Task', 'original_use' => 'How It Was Used', 'second_task' => 'Second Real Task', 'reuse_method' => 'How It Was Reused', 'reuse_result' => 'Reuse Result', 'reuse_changes' => 'Changes Made', 'reusability_learning' => 'What Was Learned', 'reusability_improvement' => 'What Could Improve'] as $key => $label)
        @if(filled($values[$key] ?? null))<div class="check"><strong>{{ $label }}</strong><x-formatted-text :text="is_scalar($values[$key]) ? $values[$key] : json_encode($values[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)" /></div>@endif
    @endforeach
</div>
<div class="card" style="margin-top:18px">
    <h2>Reusable AI Asset</h2>
    <p><strong>{{ $filename }}</strong></p>
    @if(in_array($extension, ['doc', 'xls', 'zip', 'tar'], true))
        <p class="muted">Preview unavailable for this archive file type.</p>
        <a class="btn" href="{{ route('training.reusable-assets.download', $version) }}">Download</a>
    @else
        <x-evidence-actions :evidence="$asset" :view-url="route('training.reusable-assets.view', $version)" :download-url="route('training.reusable-assets.download', $version)" :preview-url="route('training.reusable-assets.preview', $version)" :modal-id="'reusable-asset-detail-preview-'.$version->id" />
    @endif
</div>
<div class="actions"><a class="btn secondary" href="{{ route('training.reusable-assets.index') }}">Back to Library</a></div>
@endsection
