@props([
    'recording',
    'week',
    'showDownload' => true,
    'previewLabel' => 'Preview Video',
])

<div id="weekly-video-actions-{{ $recording->id }}" class="weekly-video-actions">
    <button
        type="button"
        class="btn secondary"
        data-weekly-video-preview
        data-video-url="{{ route('training.video-recording.view', [$week, $recording]) }}"
    >{{ $previewLabel }}</button>
    @if($showDownload)
        <a class="btn secondary" href="{{ route('training.video-recording.download', [$week, $recording]) }}">Download Video</a>
    @endif
    <dialog
        id="weekly-video-dialog-{{ $recording->id }}"
        class="weekly-video-modal"
        data-weekly-video-dialog
        aria-labelledby="weekly-video-title-{{ $recording->id }}"
    >
        <div class="weekly-video-modal-content">
            <div class="weekly-video-modal-header">
                <h2 id="weekly-video-title-{{ $recording->id }}">Week {{ $week->week_number }} Video Preview</h2>
                <button type="button" class="weekly-video-modal-close" data-weekly-video-close aria-label="Close video preview">Close</button>
            </div>
            <p class="muted">{{ $recording->original_filename }}</p>
            <video class="weekly-video-player" controls preload="metadata" playsinline data-weekly-video-player>
                Your browser does not support video playback.
            </video>
        </div>
    </dialog>
</div>
<script>
    (() => {
        const root = document.getElementById('weekly-video-actions-{{ $recording->id }}');
        const open = root?.querySelector('[data-weekly-video-preview]');
        const dialog = root?.querySelector('[data-weekly-video-dialog]');
        const close = root?.querySelector('[data-weekly-video-close]');
        const player = root?.querySelector('[data-weekly-video-player]');
        if (!root || !open || !dialog || !close || !player) {
            return;
        }

        let previousFocus;
        open.addEventListener('click', () => {
            previousFocus = document.activeElement;
            player.src = open.dataset.videoUrl;
            dialog.showModal();
            close.focus();
        });
        close.addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => {
            player.pause();
            player.removeAttribute('src');
            player.load();
            previousFocus?.focus();
        });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    })();
</script>
