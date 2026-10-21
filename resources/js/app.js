import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import 'flatpickr/dist/themes/dark.css';

const initializeMentorDateTimePickers = () => {
    document.querySelectorAll('input[name="scheduled_at"]').forEach((input) => {
        if (input._flatpickr) {
            return;
        }

        input.dataset.mentorDatetimePicker = 'true';
        input.classList.add('mentor-datetime-picker');
        input.type = 'text';
        flatpickr(input, {
            allowInput: false,
            altFormat: 'F j, Y \\a\\t h:i K',
            altInput: true,
            dateFormat: 'Y-m-d\\TH:i',
            disableMobile: true,
            enableTime: true,
            minuteIncrement: Number(input.dataset.minuteIncrement || 15),
            minDate: input.dataset.minDate || input.getAttribute('min') || 'today',
            time_24hr: false,
        });
    });

    document.querySelectorAll('input[data-mentor-time-picker]').forEach((input) => {
        if (input._flatpickr) {
            return;
        }

        input.type = 'text';
        flatpickr(input, {
            allowInput: false,
            altFormat: 'h:i K',
            altInput: true,
            dateFormat: 'H:i',
            disableMobile: true,
            enableTime: true,
            noCalendar: true,
            minuteIncrement: 15,
            time_24hr: false,
        });
    });
};

const initializeSidebarDrawer = () => {
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.getElementById('app-sidebar');

    if (!toggle || !sidebar) {
        return;
    }

    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const isOpen = () => sidebar.hasAttribute('data-open');

    const setOpen = (open) => {
        sidebar.toggleAttribute('data-open', open);
        toggle.setAttribute('aria-expanded', String(open));

        if (backdrop) {
            backdrop.hidden = !open;
        }

        if (open) {
            sidebar.querySelector('a, button')?.focus();
        }
    };

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
 * Open the native date picker when anything inside a `[data-date-picker]` field is clicked, not only the small
 * calendar indicator. Browsers without `showPicker()` (or that refuse it) just focus the input.
 */
const initializeDatePickers = () => {
    document.querySelectorAll('[data-date-picker]').forEach((field) => {
        const input = field.querySelector('input[type="date"]');

        if (!input) {
            return;
        }

        field.addEventListener('click', (event) => {
            if (typeof input.showPicker !== 'function') {
                if (event.target !== input) {
                    input.focus();
                }

                return;
            }

            event.preventDefault();

            try {
                input.showPicker();
            } catch {
                input.focus();
            }
        });
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

                const panel = textarea.closest('.markdown-field')?.querySelector('[data-markdown-preview]');

                if (panel) {
                    panel.hidden = true;
                }
            });
        });
    });
};

/**
 * On the AI scoring settings page, show the selected provider's model label and hint (from the option's data
 * attributes), update the "API key:" part of the status summary to whether that provider has a stored key, and copy
 * the current provider, model and API key into the `[data-ai-test-form]` before it submits.
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
    const status = document.getElementById('ai-status');
    const testForm = document.querySelector('[data-ai-test-form]');

    const sync = () => {
        const option = provider.options[provider.selectedIndex] ?? provider.options[0];
        const apiKeyStatus = option.dataset.apiKeyConfigured === 'true' ? 'Configured' : 'Not configured';

        modelLabel.textContent = option.dataset.modelLabel;
        modelHelp.textContent = option.dataset.modelHelp;
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
 * interview type is not Mock Interview.
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
        const isFinalBookingShown = type.value !== 'mock';

        finalField.hidden = !isFinalBookingShown;
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
 * poll its status every two seconds and reload the page once the sync has finished.
 */
const initializeGoogleDriveSyncStatus = () => {
    const status = document.querySelector('[data-drive-sync-status-url]');

    if (!status) {
        return;
    }

    const finishedStatuses = ['completed', 'completed_with_errors', 'failed'];

    const poll = async () => {
        try {
            const response = await fetch(status.dataset.driveSyncStatusUrl, { headers: { Accept: 'application/json' } });
            const data = await response.json();

            if (finishedStatuses.includes(data.status)) {
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

const initializePage = () => {
    initializeMentorDateTimePickers();
    initializeSidebarDrawer();
    initializePageTabs();
    initializeDatePickers();
    initializeMarkdownFlushOnSubmit();
    initializeAiProviderSettings();
    initializeInterviewerTypeFields();
    initializeGoogleDrivePicker();
    initializeGoogleDriveSyncStatus();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePage, { once: true });
} else {
    initializePage();
}
