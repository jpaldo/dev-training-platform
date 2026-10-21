import TurndownService from 'turndown';
import { gfm } from 'turndown-plugin-gfm';

const turndown = new TurndownService({
    headingStyle: 'atx',
    codeBlockStyle: 'fenced',
    bulletListMarker: '-',
    emDelimiter: '*',
    strongDelimiter: '**',
});
turndown.use(gfm);

function enforceMaxLength(textarea) {
    if (!textarea.maxLength || textarea.maxLength <= 0) return;
    if (textarea.value.length <= textarea.maxLength) return;
    textarea.value = textarea.value.slice(0, textarea.maxLength);
    if (textarea.selectionStart > textarea.maxLength) textarea.selectionStart = textarea.maxLength;
    if (textarea.selectionEnd > textarea.maxLength) textarea.selectionEnd = textarea.maxLength;
}

function convertPaste(textarea, event) {
    const html = event.clipboardData && event.clipboardData.getData('text/html');
    if (!html) return;
    event.preventDefault();
    let markdown = turndown.turndown(html).replace(/\n{3,}/g, '\n\n').trim();
    const room = textarea.maxLength > 0
        ? textarea.maxLength - (textarea.value.length - (textarea.selectionEnd - textarea.selectionStart))
        : Infinity;
    markdown = markdown.slice(0, Math.max(0, room));
    textarea.setRangeText(markdown, textarea.selectionStart, textarea.selectionEnd, 'end');
    enforceMaxLength(textarea);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.focus();
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[char]));
}

function csrfToken(textarea) {
    return textarea.closest('form')?.querySelector('input[name="_token"]')?.value || '';
}

function previewUrl() {
    return new URL('/markdown/preview', window.location.origin).toString();
}

function renderPreview(panel, textarea, state, content) {
    const token = csrfToken(textarea);
    if (state.cacheSource === content && state.cacheToken === token && state.cache !== null) {
        panel.innerHTML = state.cache;
        return;
    }
    if (!content.trim()) {
        panel.innerHTML = '<p class="text-muted">Nothing to preview.</p>';
        return;
    }
    panel.textContent = 'Rendering…';
    const body = new FormData();
    body.append('_token', token);
    body.append('content', content);
    fetch(previewUrl(), {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body,
    })
        .then(async response => {
            if (!response.ok) throw new Error('preview_failed');
            const data = await response.json();
            state.cache = data.html;
            state.cacheSource = content;
            state.cacheToken = token;
            panel.innerHTML = data.html;
        })
        .catch(() => {
            panel.innerHTML = `<pre>${escapeHtml(content)}</pre>`;
        });
}

document.querySelectorAll('[data-markdown-source]').forEach((textarea) => {
    const wrap = textarea.closest('.markdown-field');
    const panel = wrap?.querySelector('[data-markdown-preview]');
    if (!wrap || !panel) return;

    const counter = wrap.querySelector('[data-char-count]');
    const updateCount = () => {
        if (!counter || !textarea.maxLength) return;
        counter.textContent = textarea.value.length.toLocaleString('en-US') + ' / ' + textarea.maxLength.toLocaleString('en-US') + ' characters';
    };

    const tabs = Array.from(wrap.querySelectorAll('[data-markdown-mode]'));
    const state = { mode: 'write', cache: null, cacheSource: null, cacheToken: null, timer: null };

    const setMode = mode => {
        state.mode = mode;
        const isPreview = mode === 'preview';
        tabs.forEach(tab => {
            const active = tab.dataset.markdownMode === mode;
            tab.setAttribute('aria-pressed', String(active));
        });
        textarea.hidden = isPreview;
        panel.hidden = !isPreview;
        if (isPreview) renderPreview(panel, textarea, state, textarea.value);
    };

    tabs.forEach(tab => {
        tab.addEventListener('click', () => setMode(tab.dataset.markdownMode));
    });

    textarea.addEventListener('paste', event => convertPaste(textarea, event));
    textarea.addEventListener('input', () => {
        enforceMaxLength(textarea);
        updateCount();
    });
    textarea.addEventListener('input', () => {
        if (state.mode !== 'preview') return;
        clearTimeout(state.timer);
        state.timer = window.setTimeout(() => renderPreview(panel, textarea, state, textarea.value), 300);
    });
    textarea.addEventListener('change', () => {
        if (state.mode === 'preview') renderPreview(panel, textarea, state, textarea.value);
    });

    updateCount();
});