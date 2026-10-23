{{--
    The actions for a participant's chosen weekly video: an "Open in Drive" link to the file in Google Drive, in a new
    tab. The app keeps no copy of the video, so there is no player or download.
--}}
@props(['recording'])

<div id="weekly-video-actions-{{ $recording->id }}" class="flex flex-wrap items-center gap-2.5" data-weekly-video-actions>
    <x-ui.button variant="secondary" :href="$recording->driveViewUrl()" target="_blank" rel="noopener noreferrer">Open in Drive<span class="sr-only">: {{ $recording->name }} (opens in a new tab)</span></x-ui.button>
</div>
