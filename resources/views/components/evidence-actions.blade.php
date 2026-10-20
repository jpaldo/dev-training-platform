@props(['evidence', 'viewUrl' => null, 'downloadUrl' => null, 'previewUrl' => null, 'modalId' => null])

@php
    $filename = $evidence->original_filename ?: ($evidence->title ?: 'Evidence');
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = strtolower((string) $evidence->mime_type);
    $type = match (true) {
        in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) || in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) => 'image',
        $mime === 'application/pdf' || $extension === 'pdf' => 'pdf',
        str_starts_with($mime, 'video/') || in_array($extension, ['mp4', 'webm', 'mov', 'm4v'], true) => 'video',
        in_array($extension, ['txt', 'md', 'log', 'json', 'xml', 'csv', 'xlsx', 'docx'], true) => 'structured',
        default => 'unsupported',
    };
    $viewUrl ??= route('training.evidence.view', $evidence);
    $downloadUrl ??= route('training.evidence.download', $evidence);
    $previewUrl ??= route('training.evidence.preview', $evidence);
    $modalId ??= 'evidence-preview-'.$evidence->id;
@endphp

@if($type !== 'unsupported')
    <button class="btn secondary" type="button" data-evidence-preview-open data-evidence-preview-dialog="{{ $modalId }}">View</button>
    <dialog id="{{ $modalId }}" class="evidence-preview-modal" aria-labelledby="{{ $modalId }}-title">
        <div class="evidence-preview-modal-content">
            <div class="evidence-preview-modal-header">
                <div><h2 id="{{ $modalId }}-title">{{ $filename }}</h2><p class="muted">{{ strtoupper($extension ?: $mime ?: 'file') }}</p></div>
                <button class="evidence-preview-modal-close" type="button" data-evidence-preview-close aria-label="Close evidence preview">×</button>
            </div>
            <p class="muted" data-evidence-preview-loading role="status">Loading preview…</p>
            <p class="error" data-evidence-preview-error role="alert" hidden>Unable to preview this evidence.</p>
            <div class="evidence-preview-area">
                @if($type === 'image')
                    <img class="evidence-preview-image" alt="{{ $filename }}" data-evidence-preview-image data-preview-src="{{ $viewUrl }}" hidden>
                @elseif($type === 'pdf')
                    <iframe class="evidence-preview-frame" title="{{ $filename }}" data-evidence-preview-frame data-preview-src="{{ $viewUrl }}" hidden></iframe>
                    <p class="muted" data-evidence-preview-fallback hidden>PDF preview is not available in this browser.</p>
                @elseif($type === 'video')
                    <video class="evidence-preview-video" controls preload="metadata" playsinline data-evidence-preview-video data-preview-src="{{ $viewUrl }}" hidden><source type="{{ $mime ?: 'video/mp4' }}"></video>
                @else
                    <pre class="evidence-preview-text" data-evidence-preview-text hidden></pre>
                    <div class="evidence-preview-table-wrap" data-evidence-preview-table-wrap hidden><table class="evidence-preview-table" data-evidence-preview-table></table></div>
                @endif
            </div>
            <div class="actions"><button class="btn secondary" type="button" data-evidence-preview-close>Close</button><a class="btn" href="{{ $downloadUrl }}">Download</a></div>
        </div>
    </dialog>
    <script>
        (() => {
            const open = document.querySelector('[data-evidence-preview-dialog="{{ $modalId }}"]');
            const dialog = document.getElementById('{{ $modalId }}');
            if (!open || !dialog) return;
            const loading = dialog.querySelector('[data-evidence-preview-loading]');
            const error = dialog.querySelector('[data-evidence-preview-error]');
            const image = dialog.querySelector('[data-evidence-preview-image]');
            const frame = dialog.querySelector('[data-evidence-preview-frame]');
            const fallback = dialog.querySelector('[data-evidence-preview-fallback]');
            const video = dialog.querySelector('[data-evidence-preview-video]');
            const text = dialog.querySelector('[data-evidence-preview-text]');
            const tableWrap = dialog.querySelector('[data-evidence-preview-table-wrap]');
            const table = dialog.querySelector('[data-evidence-preview-table]');
            const closeButtons = dialog.querySelectorAll('[data-evidence-preview-close]');
            const reset = () => {
                loading.hidden = false; error.hidden = true;
                [image, frame, video, text, tableWrap, fallback].filter(Boolean).forEach((element) => { element.hidden = true; });
                if (text) text.textContent = '';
                if (table) table.replaceChildren();
                dialog.querySelectorAll('[data-evidence-preview-note]').forEach((note) => note.remove());
            };
            const showError = (message = 'Unable to preview this evidence.') => { loading.hidden = true; error.textContent = message; error.hidden = false; };
            const close = () => { dialog.close(); open.focus(); };
            const addCell = (row, value, heading = false) => { const cell = document.createElement(heading ? 'th' : 'td'); cell.textContent = value ?? ''; row.appendChild(cell); };
            const showStructured = async () => {
                const response = await fetch('{{ $previewUrl }}', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('preview request failed');
                const data = await response.json();
                if (data.kind === 'table' || data.kind === 'workbook') {
                    const head = document.createElement('thead'); const headRow = document.createElement('tr');
                    (data.headers || []).forEach((value) => addCell(headRow, value, true)); head.appendChild(headRow); table.appendChild(head);
                    const body = document.createElement('tbody'); (data.rows || []).forEach((values) => { const row = document.createElement('tr'); values.forEach((value) => addCell(row, value)); body.appendChild(row); }); table.appendChild(body); tableWrap.hidden = false;
                } else { text.textContent = data.content || 'No preview content available.'; text.hidden = false; }
                loading.hidden = true;
                if (data.truncated) { const note = document.createElement('p'); note.className = 'muted'; note.dataset.evidencePreviewNote = 'true'; note.textContent = data.truncated; tableWrap.parentElement.appendChild(note); }
            };
            open.addEventListener('click', async () => {
                reset(); dialog.showModal(); dialog.querySelector('[data-evidence-preview-close]')?.focus();
                try {
                    @if($type === 'image')
                        image.onload = () => { loading.hidden = true; image.hidden = false; }; image.onerror = () => showError(); image.src = image.dataset.previewSrc;
                    @elseif($type === 'pdf')
                        frame.onload = () => { loading.hidden = true; frame.hidden = false; }; frame.onerror = () => { loading.hidden = true; fallback.hidden = false; }; frame.src = frame.dataset.previewSrc;
                    @elseif($type === 'video')
                        video.onloadedmetadata = () => { loading.hidden = true; video.hidden = false; }; video.onerror = () => showError(); video.querySelector('source').src = video.dataset.previewSrc; video.load();
                    @else
                        await showStructured();
                    @endif
                } catch (exception) { showError(); }
            });
            closeButtons.forEach((button) => button.addEventListener('click', close));
            dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
            dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
        })();
    </script>
@else
    <a class="btn secondary" href="{{ $viewUrl }}">View</a>
    <a class="btn secondary" href="{{ $downloadUrl }}">Download</a>
@endif
