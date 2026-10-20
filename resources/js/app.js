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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeMentorDateTimePickers, { once: true });
} else {
    initializeMentorDateTimePickers();
}
