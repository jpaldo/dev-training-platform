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

const initializePage = () => {
    initializeMentorDateTimePickers();
    initializeSidebarDrawer();
    initializePageTabs();
    initializeDatePickers();
    initializeMarkdownFlushOnSubmit();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePage, { once: true });
} else {
    initializePage();
}
