{{--
    A participant's chosen weekly video: its name and details (size and when it was chosen), an "Open in Drive" link to
    the file in Google Drive (new tab), the lazily loaded Drive preview and a help line for when the video does not
    open. The app keeps no copy of the video, so there is no player or download, and the page never reads Drive (Q6),
    so the help line explains what to do when the file has gone. The help line speaks to the participant, or with
    `for-mentor` to the mentor. The slot holds more actions next to "Open in Drive" (the participant's Change Video).
    The mentor version also says whether the app shared the video with the company's Google Workspace domain
    (`share-domain`, the configured domain; null names no domain).
--}}
@props(['recording', 'forMentor' => false, 'shareDomain' => null])

<div id="weekly-video-actions-{{ $recording->id }}" class="grid w-full min-w-0 grid-cols-1 gap-3" data-weekly-video-actions>
    <div class="grid min-w-0 gap-1">
        <p class="font-bold wrap-anywhere">{{ $recording->name }}</p>
        @if($recording->detailsLabel() !== '')
            <p class="text-sm text-muted">{{ $recording->detailsLabel() }}</p>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2.5">
        <x-ui.button variant="secondary" :href="$recording->driveViewUrl()" target="_blank" rel="noopener noreferrer">Open in Drive<span class="sr-only">: {{ $recording->name }} (opens in a new tab)</span></x-ui.button>
        {{ $slot }}
    </div>

    <x-drive-preview :preview-url="$recording->drivePreviewUrl()" :title="$recording->name" />

    @if($forMentor)
        @if($recording->isShared())
            <p class="text-sm font-bold text-emerald-200" data-weekly-video-sharing>Shared with {{ $shareDomain ?? "the company's Google Workspace" }}</p>
        @else
            <p class="text-sm font-bold text-amber-200" data-weekly-video-sharing>Not shared yet. Ask the participant to share it in Google Drive.</p>
        @endif
    @endif

    <p class="text-sm/relaxed text-muted">
        @if($forMentor)
            Video not opening? It may have been deleted, moved to the trash or unshared in the participant's Google Drive. Ask them to choose it again.
        @else
            Video not opening? It may have been deleted, moved to the trash or unshared in your Google Drive. Choose it again.
        @endif
    </p>
</div>
