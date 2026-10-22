/**
 * The sidebar's `[data-sidebar-navigation]` scrolls on its own so the account block stays pinned below it; when the
 * current page's link sits outside that scroll area, scroll it to the middle so the reader can see where they are.
 */
const initializeSidebarCurrentLink = () => {
    const navigation = document.querySelector('[data-sidebar-navigation]');
    const currentLink = navigation?.querySelector('[aria-current="page"]');

    if (!currentLink) {
        return;
    }

    const navigationBox = navigation.getBoundingClientRect();
    const linkBox = currentLink.getBoundingClientRect();

    if (linkBox.top >= navigationBox.top && linkBox.bottom <= navigationBox.bottom) {
        return;
    }

    navigation.scrollTop += linkBox.top - navigationBox.top - (navigation.clientHeight - linkBox.height) / 2;
};

/**
 * Open and close the `#app-sidebar` drawer (below `lg`) from the `[data-sidebar-toggle]` button, any
 * `[data-sidebar-close]` element and Escape. While the drawer is open the page behind it does not scroll, and the
 * sidebar only animates while `data-sliding` is set (from a toggle until its transition ends), so resizing across
 * `lg` does not slide it in or out.
 */
const initializeSidebarDrawer = () => {
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.getElementById('app-sidebar');

    if (!toggle || !sidebar) {
        return;
    }

    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const isOpen = () => sidebar.hasAttribute('data-open');

    const setOpen = (open) => {
        sidebar.setAttribute('data-sliding', '');
        sidebar.toggleAttribute('data-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('max-lg:overflow-hidden', open);

        if (backdrop) {
            backdrop.hidden = !open;
        }

        if (open) {
            sidebar.querySelector('a, button')?.focus();
        }
    };

    const stopSliding = (event) => {
        if (event.target === sidebar) {
            sidebar.removeAttribute('data-sliding');
        }
    };

    sidebar.addEventListener('transitionend', stopSliding);
    sidebar.addEventListener('transitioncancel', stopSliding);
    toggle.addEventListener('click', () => setOpen(!isOpen()));
    document.querySelectorAll('[data-sidebar-close]').forEach((element) => {
        element.addEventListener('click', () => {
            setOpen(false);
            toggle.focus();
        });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            setOpen(false);
            toggle.focus();
        }
    });
};

const initializePageTabs = () => {
    document.querySelectorAll('[data-page-tabs]').forEach((container) => {
        const tabs = [...container.querySelectorAll('[role="tab"]')];

        const select = (tab, { focus = false, updateHash = true } = {}) => {
            tabs.forEach((candidate) => {
                const selected = candidate === tab;
                candidate.setAttribute('aria-selected', String(selected));
                candidate.tabIndex = selected ? 0 : -1;
                document.getElementById(candidate.getAttribute('aria-controls')).hidden = !selected;
            });

            if (focus) {
                tab.focus();
            }

            if (updateHash) {
                history.replaceState(null, '', `#${tab.dataset.tabKey}`);
            }
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => select(tab));
            tab.addEventListener('keydown', (event) => {
                const targetIndex = {
                    ArrowRight: (index + 1) % tabs.length,
                    ArrowLeft: (index - 1 + tabs.length) % tabs.length,
                    Home: 0,
                    End: tabs.length - 1,
                }[event.key];

                if (targetIndex !== undefined) {
                    event.preventDefault();
                    select(tabs[targetIndex], { focus: true });
                }
            });
        });

        const tabFromHash = tabs.find((tab) => `#${tab.dataset.tabKey}` === window.location.hash);

        if (tabFromHash) {
            select(tabFromHash, { updateHash: false });
        }
    });
};

/**
 * Before a `[data-markdown-flush-on-submit]` form submits, switch its Markdown fields back to Write mode: show each
 * source textarea and hide its preview panel.
 */
const initializeMarkdownFlushOnSubmit = () => {
    document.querySelectorAll('[data-markdown-flush-on-submit]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('[data-markdown-source]').forEach((textarea) => {
                textarea.hidden = false;

                const panel = textarea.closest('[data-markdown-field]')?.querySelector('[data-markdown-preview]');

                if (panel) {
                    panel.hidden = true;
                }
            });
        });
    });
};

/**
 * The "N / max characters" counter text of a `maxlength` control, with thousands separators.
 */
const characterCountText = (control) => `${control.value.length.toLocaleString('en-US')} / ${control.maxLength.toLocaleString('en-US')} characters`;

/**
 * Keep the `[data-char-count]` text of each `[data-character-counter]` field at "N / max characters" for its
 * `maxlength` input or plain textarea. Markdown fields count their own characters in `initializeMarkdownFields`.
 */
const initializeCharacterCounters = () => {
    document.querySelectorAll('[data-character-counter]').forEach((field) => {
        const control = field.querySelector('input[maxlength], textarea[maxlength]:not([data-markdown-source])');
        const counter = field.querySelector('[data-char-count]');

        if (!control || !counter) {
            return;
        }

        const update = () => {
            counter.textContent = characterCountText(control);
        };

        control.addEventListener('input', update);
        update();
    });
};

let markdownConverter;

/**
 * Load the HTML to Markdown converter for pasted rich text on first use, so pages without a Markdown field do not
 * download `turndown` (a failed load is retried on the next paste).
 */
const loadMarkdownConverter = () => {
    markdownConverter ??= Promise.all([import('turndown'), import('turndown-plugin-gfm')]).then(([{ default: TurndownService }, { gfm }]) => {
        const converter = new TurndownService({
            headingStyle: 'atx',
            codeBlockStyle: 'fenced',
            bulletListMarker: '-',
            emDelimiter: '*',
            strongDelimiter: '**',
        });
        converter.use(gfm);

        return converter;
    }).catch((error) => {
        markdownConverter = undefined;

        throw error;
    });

    return markdownConverter;
};

/**
 * Make each `x-markdown-field` (`[data-markdown-field]`) work: the Write / Preview buttons (`[data-markdown-mode]`)
 * switch between the `[data-markdown-source]` textarea and the `[data-markdown-preview]` panel, which shows the
 * Markdown rendered by the root's `data-preview-url` (cached per content and CSRF token, refreshed while typing; the
 * escaped source if the request fails); pasted HTML becomes Markdown (or its plain text if the converter cannot load)
 * within the `maxlength`; and `[data-char-count]` reads "N / max characters".
 */
const initializeMarkdownFields = () => {
    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[character]);

    document.querySelectorAll('[data-markdown-field]').forEach((field) => {
        const textarea = field.querySelector('[data-markdown-source]');
        const panel = field.querySelector('[data-markdown-preview]');

        if (!textarea || !panel) {
            return;
        }

        const counter = field.querySelector('[data-char-count]');
        const modeButtons = [...field.querySelectorAll('[data-markdown-mode]')];
        const state = { mode: 'write', cache: null, cacheSource: null, cacheToken: null, timer: null };

        const csrfToken = () => textarea.closest('form')?.querySelector('input[name="_token"]')?.value || '';

        const enforceMaxLength = () => {
            if (textarea.maxLength <= 0 || textarea.value.length <= textarea.maxLength) {
                return;
            }

            textarea.value = textarea.value.slice(0, textarea.maxLength);
            textarea.selectionStart = Math.min(textarea.selectionStart, textarea.maxLength);
            textarea.selectionEnd = Math.min(textarea.selectionEnd, textarea.maxLength);
        };

        const updateCount = () => {
            if (counter && textarea.maxLength > 0) {
                counter.textContent = characterCountText(textarea);
            }
        };

        const renderPreview = () => {
            const content = textarea.value;
            const token = csrfToken();

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

            fetch(field.dataset.previewUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body,
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error('preview_failed');
                    }

                    const data = await response.json();
                    state.cache = data.html;
                    state.cacheSource = content;
                    state.cacheToken = token;
                    panel.innerHTML = data.html;
                })
                .catch(() => {
                    panel.innerHTML = `<pre>${escapeHtml(content)}</pre>`;
                });
        };

        const setMode = (mode) => {
            const isPreview = mode === 'preview';

            state.mode = mode;
            modeButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.markdownMode === mode)));
            textarea.hidden = isPreview;
            panel.hidden = !isPreview;

            if (isPreview) {
                renderPreview();
            }
        };

        const insertPastedText = (text, selectionStart, selectionEnd) => {
            const room = textarea.maxLength > 0
                ? textarea.maxLength - (textarea.value.length - (selectionEnd - selectionStart))
                : Infinity;

            textarea.setRangeText(text.slice(0, Math.max(0, room)), selectionStart, selectionEnd, 'end');
            enforceMaxLength();
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            textarea.focus();
        };

        modeButtons.forEach((button) => button.addEventListener('click', () => setMode(button.dataset.markdownMode)));
        textarea.addEventListener('paste', (event) => {
            const html = event.clipboardData?.getData('text/html');

            if (!html) {
                return;
            }

            event.preventDefault();

            const plainText = event.clipboardData.getData('text/plain');
            const { selectionStart, selectionEnd } = textarea;

            loadMarkdownConverter()
                .then((converter) => converter.turndown(html).replace(/\n{3,}/g, '\n\n').trim())
                .catch(() => plainText)
                .then((text) => insertPastedText(text, selectionStart, selectionEnd));
        });
        textarea.addEventListener('input', () => {
            enforceMaxLength();
            updateCount();

            if (state.mode === 'preview') {
                clearTimeout(state.timer);
                state.timer = window.setTimeout(renderPreview, 300);
            }
        });
        textarea.addEventListener('change', () => {
            if (state.mode === 'preview') {
                renderPreview();
            }
        });

        updateCount();
    });
};

/**
 * Ask for confirmation before each `form[data-confirm]` submits (the attribute holds the question); cancelling stops
 * the submit.
 */
const initializeConfirmations = () => {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });
};

/**
 * On the AI scoring settings page, show the selected provider's model label and hint and its API key placeholder and
 * hint (from the option's data attributes), update the "API key:" part of the status summary to whether that provider
 * has an effective key (Admin-saved or from the environment), and copy the current provider, model and API key into
 * the `[data-ai-test-form]` before it submits.
 */
const initializeAiProviderSettings = () => {
    const settingsForm = document.querySelector('[data-ai-settings-form]');

    if (!settingsForm) {
        return;
    }

    const provider = settingsForm.elements.provider;
    const model = settingsForm.elements.model;
    const apiKey = settingsForm.elements.api_key;
    const modelLabel = document.getElementById('model-label');
    const modelHelp = document.getElementById('model-help');
    const apiKeyHint = document.getElementById('api-key-hint');
    const status = document.getElementById('ai-status');
    const testForm = document.querySelector('[data-ai-test-form]');

    const sync = () => {
        const option = provider.options[provider.selectedIndex] ?? provider.options[0];
        const apiKeyStatus = option.dataset.apiKeyConfigured === 'true' ? 'Configured' : 'Not configured';

        modelLabel.textContent = option.dataset.modelLabel;
        modelHelp.textContent = option.dataset.modelHelp;
        apiKey.placeholder = option.dataset.apiKeyPlaceholder;
        apiKeyHint.textContent = option.dataset.apiKeyHint;
        status.textContent = status.textContent.replace(/API key: (Configured|Not configured)/, `API key: ${apiKeyStatus}`);
    };

    provider.addEventListener('change', sync);
    sync();

    testForm?.addEventListener('submit', () => {
        testForm.elements.provider.value = provider.value;
        testForm.elements.model.value = model.value;
        testForm.elements.api_key.value = apiKey.value;
    });
};

/**
 * On the interview scheduling settings page, show the Final Re-assessment booking link field
 * (`[data-final-booking-field]`) of the `[data-interviewer-type-form]` form, and require it, only while the selected
 * interview type offers Final Re-assessment interviews (its option's `data-offers-final`, set from `InterviewType`).
 */
const initializeInterviewerTypeFields = () => {
    const form = document.querySelector('[data-interviewer-type-form]');

    if (!form) {
        return;
    }

    const type = form.elements.interview_type;
    const finalField = form.querySelector('[data-final-booking-field]');
    const finalInput = form.elements.final_booking_url;

    const sync = () => {
        const isFinalBookingShown = type.selectedOptions[0]?.dataset.offersFinal === 'true';

        finalField.hidden = !isFinalBookingShown;
        finalInput.required = isFinalBookingShown;
    };

    type.addEventListener('change', sync);
    sync();
};

/**
 * On the public interviewer registration page, show the Mock Interview and Final Re-assessment booking link fields
 * (`[data-mock-booking-field]`, `[data-final-booking-field]`) of the `[data-interview-registration-form]` form, and
 * require their inputs, only for the interviews the selected type offers (its option's `data-offers-mock` and
 * `data-offers-final`, set from `InterviewType`; Both shows and requires both).
 */
const initializeInterviewRegistrationFields = () => {
    const form = document.querySelector('[data-interview-registration-form]');

    if (!form) {
        return;
    }

    const type = form.elements.interview_type;
    const mockField = form.querySelector('[data-mock-booking-field]');
    const finalField = form.querySelector('[data-final-booking-field]');
    const mockInput = form.elements.mock_booking_url;
    const finalInput = form.elements.final_booking_url;

    const sync = () => {
        const selectedType = type.selectedOptions[0];
        const isMockBookingShown = selectedType?.dataset.offersMock === 'true';
        const isFinalBookingShown = selectedType?.dataset.offersFinal === 'true';

        mockField.hidden = !isMockBookingShown;
        finalField.hidden = !isFinalBookingShown;
        mockInput.required = isMockBookingShown;
        finalInput.required = isFinalBookingShown;
    };

    type.addEventListener('change', sync);
    sync();
};

/**
 * On the profile page, open the Google Drive Picker from the `[data-drive-picker]` button (rendered only while Google
 * Drive can connect; the page loads `apis.google.com/js/api.js` alongside it) so the participant can choose an export
 * folder: fetch a short-lived token from `data-token-url`, show the folder picker over a backdrop, and post the chosen
 * folder to `data-save-url`. Problems are shown in `[data-drive-picker-error]`.
 */
const initializeGoogleDrivePicker = () => {
    const button = document.querySelector('[data-drive-picker]');
    const error = document.querySelector('[data-drive-picker-error]');
    const unavailableMessage = 'Google Drive folder selection could not be opened. Please try again.';

    const showError = (message) => {
        if (error) {
            error.textContent = message;
            error.hidden = false;
        }
    };

    if (!button) {
        return;
    }

    if (!button.dataset.apiKey || !button.dataset.appId) {
        button.disabled = true;
        showError('Google Drive folder selection is not configured. Please contact an administrator.');

        return;
    }

    const pickerStyle = document.createElement('style');
    pickerStyle.id = 'qa-picker-layout';
    pickerStyle.textContent = '.qa-picker-backdrop{position:fixed;inset:0;background:rgba(8,11,24,.68);z-index:9998}.picker-dialog-bg{position:fixed!important;inset:0!important;z-index:9998!important;background:transparent!important}.picker-dialog{position:fixed!important;top:50%!important;left:50%!important;transform:translate(-50%,-50%)!important;width:min(1000px,calc(100vw - 48px))!important;height:min(700px,calc(100vh - 48px))!important;max-width:calc(100vw - 48px)!important;max-height:calc(100vh - 48px)!important;z-index:9999!important}@media(max-width:600px){.picker-dialog{width:calc(100vw - 24px)!important;height:calc(100vh - 24px)!important;max-width:calc(100vw - 24px)!important;max-height:calc(100vh - 24px)!important}}';
    document.head.appendChild(pickerStyle);

    let pickerLoadAttempts = 0;

    const loadPicker = () => {
        if (!window.gapi) {
            if (++pickerLoadAttempts > 100) {
                showError(unavailableMessage);

                return;
            }

            window.setTimeout(loadPicker, 100);

            return;
        }

        try {
            window.gapi.load('picker', {
                callback: () => {
                    button.dataset.pickerReady = '1';
                },
            });
        } catch {
            showError(unavailableMessage);
        }
    };

    const submitFolder = (folder) => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = button.dataset.saveUrl;

        const fields = {
            _token: document.querySelector('input[name="_token"]')?.value || '',
            folder_id: folder.id,
            folder_name: folder.name || '',
            resource_key: folder.resourceKey || '',
            drive_id: folder.driveId || '',
        };

        Object.entries(fields).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    };

    const openPicker = (accessToken) => {
        const { picker } = window.google ?? {};

        if (!picker || button.dataset.pickerReady !== '1') {
            throw new Error(unavailableMessage);
        }

        const previousOverflow = document.body.style.overflow;
        const previousScrollY = window.scrollY;
        const backdrop = document.createElement('div');
        backdrop.className = 'qa-picker-backdrop';
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.appendChild(backdrop);
        document.body.style.overflow = 'hidden';

        let observer;

        const cleanup = () => {
            observer?.disconnect();
            backdrop.remove();
            document.body.style.overflow = previousOverflow;
            window.scrollTo(0, previousScrollY);
            button.disabled = false;
        };

        const view = new picker.DocsView(picker.ViewId.FOLDERS)
            .setIncludeFolders(true)
            .setSelectFolderEnabled(true)
            .setMimeTypes('application/vnd.google-apps.folder');

        const folderPicker = new picker.PickerBuilder()
            .setDeveloperKey(button.dataset.apiKey)
            .setAppId(button.dataset.appId)
            .setOAuthToken(accessToken)
            .setOrigin(`${window.location.protocol}//${window.location.host}`)
            .addView(view)
            .setSize(Math.min(1000, window.innerWidth - 48), Math.min(700, window.innerHeight - 48))
            .setCallback((data) => {
                if (data.action !== picker.Action.PICKED || !data.docs?.[0]) {
                    cleanup();

                    return;
                }

                cleanup();
                submitFolder(data.docs[0]);
            })
            .build();

        observer = new MutationObserver(() => {
            if (!document.querySelector('.picker-dialog')) {
                cleanup();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
        folderPicker.setVisible(true);
    };

    loadPicker();

    button.addEventListener('click', async () => {
        button.disabled = true;

        try {
            const response = await fetch(button.dataset.tokenUrl, { headers: { Accept: 'application/json' } });

            if (!response.ok) {
                throw new Error('Google Drive connection needs to be refreshed.');
            }

            const token = await response.json();

            if (!token.access_token) {
                throw new Error('Google Drive connection needs to be refreshed.');
            }

            const openWhenReady = () => {
                if (button.dataset.pickerReady === '1') {
                    openPicker(token.access_token);
                } else {
                    window.setTimeout(openWhenReady, 100);
                }
            };

            openWhenReady();
        } catch (exception) {
            showError(exception.message || unavailableMessage);
            button.disabled = false;
        }
    });
};

/**
 * On the profile page, while the latest Google Drive sync is still running (`data-drive-sync-status-url` is set),
 * poll its status every two seconds and reload the page once the status JSON reports it `finished`.
 */
const initializeGoogleDriveSyncStatus = () => {
    const status = document.querySelector('[data-drive-sync-status-url]');

    if (!status) {
        return;
    }

    const poll = async () => {
        try {
            const response = await fetch(status.dataset.driveSyncStatusUrl, { headers: { Accept: 'application/json' } });
            const data = await response.json();

            if (data.finished === true) {
                window.location.reload();

                return;
            }
        } catch {
            // A failed status check is retried on the next poll.
        }

        window.setTimeout(poll, 2000);
    };

    window.setTimeout(poll, 1500);
};

/**
 * Open the evidence preview `<dialog>` of each `[data-evidence-preview-open]` button (`data-evidence-preview-dialog`
 * is the dialog id) and load the file the way its `data-evidence-preview-kind` says: an image, a PDF frame, a video, or the `structured` text / table JSON fetched from the `<pre>`'s `data-preview-src`.
 * Every opening starts from the loading state. Closing (a close button, Escape or a click on the backdrop) returns
 * focus to the button.
 */
const initializeEvidencePreviews = () => {
    const cellClasses = 'border border-line px-2.5 py-2 text-left align-top whitespace-pre-wrap';

    document.querySelectorAll('[data-evidence-preview-open]').forEach((open) => {
        const dialog = document.getElementById(open.dataset.evidencePreviewDialog);

        if (!dialog) {
            return;
        }

        const loading = dialog.querySelector('[data-evidence-preview-loading]');
        const error = dialog.querySelector('[data-evidence-preview-error]');
        const image = dialog.querySelector('[data-evidence-preview-image]');
        const frame = dialog.querySelector('[data-evidence-preview-frame]');
        const video = dialog.querySelector('[data-evidence-preview-video]');
        const text = dialog.querySelector('[data-evidence-preview-text]');
        const tableWrap = dialog.querySelector('[data-evidence-preview-table-wrap]');
        const table = dialog.querySelector('[data-evidence-preview-table]');

        const reset = () => {
            loading.hidden = false;
            error.hidden = true;
            [image, frame, video, text, tableWrap].filter(Boolean).forEach((element) => {
                element.hidden = true;
            });

            if (text) {
                text.textContent = '';
            }

            table?.replaceChildren();
            dialog.querySelectorAll('[data-evidence-preview-note]').forEach((note) => note.remove());
        };

        const showError = (message = 'Unable to preview this evidence.') => {
            loading.hidden = true;
            error.textContent = message;
            error.hidden = false;
        };

        const close = () => {
            dialog.close();
            open.focus();
        };

        const addCell = (row, value, heading = false) => {
            const cell = document.createElement(heading ? 'th' : 'td');
            cell.className = heading ? `${cellClasses} sticky top-0 bg-raised` : cellClasses;
            cell.textContent = value ?? '';
            row.appendChild(cell);
        };

        const showStructured = async () => {
            const response = await fetch(text.dataset.previewSrc, { credentials: 'same-origin', headers: { Accept: 'application/json' } });

            if (!response.ok) {
                throw new Error('preview request failed');
            }

            const data = await response.json();

            if (data.kind === 'table' || data.kind === 'workbook') {
                const head = document.createElement('thead');
                const headRow = document.createElement('tr');
                (data.headers || []).forEach((value) => addCell(headRow, value, true));
                head.appendChild(headRow);
                table.appendChild(head);

                const body = document.createElement('tbody');
                (data.rows || []).forEach((values) => {
                    const row = document.createElement('tr');
                    values.forEach((value) => addCell(row, value));
                    body.appendChild(row);
                });
                table.appendChild(body);
                tableWrap.hidden = false;
            } else {
                text.textContent = data.content || 'No preview content available.';
                text.hidden = false;
            }

            loading.hidden = true;

            if (data.truncated) {
                const note = document.createElement('p');
                note.className = 'm-0 text-sm text-muted';
                note.dataset.evidencePreviewNote = 'true';
                note.textContent = data.truncated;
                tableWrap.parentElement.appendChild(note);
            }
        };

        const loaders = {
            image: () => {
                image.onload = () => {
                    loading.hidden = true;
                    image.hidden = false;
                };
                image.onerror = () => showError();
                image.src = image.dataset.previewSrc;
            },
            pdf: () => {
                frame.onload = () => {
                    loading.hidden = true;
                    frame.hidden = false;
                };
                frame.src = frame.dataset.previewSrc;
            },
            video: () => {
                video.onloadedmetadata = () => {
                    loading.hidden = true;
                    video.hidden = false;
                };
                video.onerror = () => showError();
                video.querySelector('source').src = video.dataset.previewSrc;
                video.load();
            },
            structured: showStructured,
        };

        open.addEventListener('click', async () => {
            reset();
            dialog.showModal();
            dialog.querySelector('[data-evidence-preview-close]')?.focus();

            try {
                await loaders[dialog.dataset.evidencePreviewKind]();
            } catch {
                showError();
            }
        });
        dialog.querySelectorAll('[data-evidence-preview-close]').forEach((button) => button.addEventListener('click', close));
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                close();
            }
        });
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            close();
        });
    });
};

/**
 * In each `[data-weekly-video-actions]` group, open the weekly video preview `<dialog>` from the
 * `[data-weekly-video-preview]` button: load its `data-video-url` into the player and focus the close button. Closing
 * (the close button, Escape or a click on the backdrop) stops and unloads the video and returns focus to where it was.
 */
const initializeWeeklyVideoPreviews = () => {
    document.querySelectorAll('[data-weekly-video-actions]').forEach((root) => {
        const open = root.querySelector('[data-weekly-video-preview]');
        const dialog = root.querySelector('[data-weekly-video-dialog]');
        const close = root.querySelector('[data-weekly-video-close]');
        const player = root.querySelector('[data-weekly-video-player]');

        if (!open || !dialog || !close || !player) {
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
    });
};

/**
 * On each `[data-weekly-video-form]`, check the chosen `[data-weekly-video-input]` file against the form's
 * `data-max-bytes` before upload: show `[data-weekly-video-size-error]`, mark the input invalid with the form's
 * `data-max-size-message` and block the submit while the file is too large.
 */
const initializeWeeklyVideoSizeLimit = () => {
    document.querySelectorAll('[data-weekly-video-form]').forEach((form) => {
        const input = form.querySelector('[data-weekly-video-input]');
        const error = form.querySelector('[data-weekly-video-size-error]');
        const maxBytes = Number(form.dataset.maxBytes || 0);
        const maxSizeMessage = form.dataset.maxSizeMessage || '';
        const validate = () => {
            const tooLarge = Boolean(input?.files?.[0] && input.files[0].size > maxBytes);

            if (error) {
                error.hidden = !tooLarge;
            }
            input?.setCustomValidity(tooLarge ? maxSizeMessage : '');

            return !tooLarge;
        };

        input?.addEventListener('change', validate);
        form.addEventListener('submit', (event) => {
            if (!validate()) {
                event.preventDefault();
            }
        });
    });
};

const initializePage = () => {
    initializeSidebarDrawer();
    initializeSidebarCurrentLink();
    initializePageTabs();
    initializeMarkdownFlushOnSubmit();
    initializeCharacterCounters();
    initializeMarkdownFields();
    initializeConfirmations();
    initializeAiProviderSettings();
    initializeInterviewerTypeFields();
    initializeInterviewRegistrationFields();
    initializeGoogleDrivePicker();
    initializeGoogleDriveSyncStatus();
    initializeEvidencePreviews();
    initializeWeeklyVideoPreviews();
    initializeWeeklyVideoSizeLimit();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePage, { once: true });
} else {
    initializePage();
}
