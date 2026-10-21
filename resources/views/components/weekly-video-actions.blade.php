@props([
    'recording',
    'week',
    'showDownload' => true,
    'previewLabel' => 'Preview Video',
])

<div id="weekly-video-actions-{{ $recording->id }}" class="flex flex-wrap items-center gap-2.5" data-weekly-video-actions>
    <x-ui.button
        variant="secondary"
        data-weekly-video-preview
        data-video-url="{{ route('training.video-recording.view', [$week, $recording]) }}"
    >{{ $previewLabel }}</x-ui.button>
    @if($showDownload)
        <x-ui.button variant="secondary" :href="route('training.video-recording.download', [$week, $recording])">Download Video</x-ui.button>
    @endif
    <dialog
        id="weekly-video-dialog-{{ $recording->id }}"
        data-weekly-video-dialog
        aria-labelledby="weekly-video-title-{{ $recording->id }}"
        class="m-auto max-h-[min(90vh,760px)] w-[min(95vw,1000px)] max-w-none rounded-[18px] border border-line bg-surface p-0 text-base text-slate-100 backdrop:bg-[rgb(3_5_15/0.8)] max-md:w-[calc(100vw-20px)]"
    >
        <div class="grid max-h-[90vh] grid-cols-1 gap-3.5 overflow-y-auto p-5.5 max-md:p-4">
            <div class="flex items-center justify-between gap-4">
                <h2 id="weekly-video-title-{{ $recording->id }}" class="m-0 text-xl font-extrabold">Week {{ $week->week_number }} Video Preview</h2>
                <x-ui.button variant="secondary" data-weekly-video-close aria-label="Close video preview">Close</x-ui.button>
            </div>
            <p class="m-0 text-sm text-muted wrap-anywhere">{{ $recording->original_filename }}</p>
            <video class="max-h-[70vh] w-full rounded-xl bg-black" controls preload="metadata" playsinline data-weekly-video-player>
                Your browser does not support video playback.
            </video>
        </div>
    </dialog>
</div>
