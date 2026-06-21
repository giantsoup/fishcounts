
import Alpine from 'alpinejs';
import mask from '@alpinejs/mask';
import flatpickr from 'flatpickr';
import TomSelect from 'tom-select';
import 'flatpickr/dist/flatpickr.css';
import 'tom-select/dist/css/tom-select.css';

window.Alpine = Alpine;

Alpine.plugin(mask);

const isMultipleSelect = (select) => select.multiple || select.dataset.selectMode === 'multiple';

const selectPlugins = (select) => (isMultipleSelect(select) ? ['remove_button'] : []);

const selectConfig = (select) => {
    const isMultiple = isMultipleSelect(select);
    const placeholder = select.dataset.placeholder || '';

    return {
        allowEmptyOption: ! isMultiple,
        closeAfterSelect: ! isMultiple,
        create: false,
        hidePlaceholder: isMultiple,
        hideSelected: isMultiple,
        maxOptions: 500,
        plugins: selectPlugins(select),
        placeholder,
        render: {
            item: (data, escape) => `<div>${escape(data.text)}</div>`,
            option: (data, escape) => `<div>${escape(data.text)}</div>`,
        },
    };
};

const initializeSelects = (root = document) => {
    root.querySelectorAll('[data-enhance="select"]').forEach((select) => {
        if (select.tomselect || select.dataset.enhanced === 'true') {
            return;
        }

        select.dataset.enhanced = 'true';
        new TomSelect(select, selectConfig(select));
    });
};

const formatAmericanDateInput = (value) => {
    if (value.includes('/')) {
        return value
            .replace(/[^\d/]/g, '')
            .split('/')
            .slice(0, 3)
            .map((part, index) => part.slice(0, index === 2 ? 4 : 2))
            .join('/');
    }

    const digits = value.replace(/\D/g, '').slice(0, 8);

    if (digits.length <= 2) {
        return digits;
    }

    if (digits.length <= 4) {
        return `${digits.slice(0, 2)}/${digits.slice(2)}`;
    }

    return `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
};

const initializeDateMask = (picker, input) => {
    const visibleInput = picker.altInput || input;
    const originalId = input.getAttribute('id');

    if (originalId && visibleInput !== input) {
        input.setAttribute('id', `${originalId}_iso`);
        visibleInput.setAttribute('id', originalId);
    }

    visibleInput.setAttribute('placeholder', 'MM/DD/YYYY');
    visibleInput.setAttribute('inputmode', 'numeric');
    visibleInput.setAttribute('autocomplete', 'off');

    const syncDateValue = () => {
        const value = visibleInput.value.trim();

        if (value === '') {
            picker.clear();

            return;
        }

        if (/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(value)) {
            picker.setDate(value, true, 'n/j/Y');
        }
    };

    visibleInput.addEventListener('input', () => {
        const formattedValue = formatAmericanDateInput(visibleInput.value);

        if (formattedValue !== visibleInput.value) {
            visibleInput.value = formattedValue;
        }
    });

    visibleInput.addEventListener('blur', syncDateValue);
    visibleInput.form?.addEventListener('submit', syncDateValue);
};

const initializeDates = (root = document) => {
    root.querySelectorAll('[data-enhance="date"]').forEach((input) => {
        if (input._flatpickr || input.dataset.enhanced === 'true') {
            return;
        }

        input.dataset.enhanced = 'true';
        const picker = flatpickr(input, {
            allowInput: true,
            altFormat: 'm/d/Y',
            altInput: true,
            ariaDateFormat: 'm/d/Y',
            dateFormat: 'Y-m-d',
            maxDate: input.getAttribute('max') || undefined,
            minDate: input.getAttribute('min') || undefined,
        });

        initializeDateMask(picker, input);
    });
};

const updateBackfillPollStatus = (status, message, isActive) => {
    if (! status) {
        return;
    }

    status.innerHTML = `
        <span class="h-2 w-2 rounded-full ${isActive ? 'bg-blue-600' : 'bg-gray-300'}"></span>
        <span>${message}</span>
    `;
};

const initializeBackfillPolling = (root = document) => {
    root.querySelectorAll('[data-backfill-poll]').forEach((panel) => {
        if (panel.dataset.enhanced === 'true' || panel.dataset.backfillPollActive !== 'true') {
            return;
        }

        const target = panel.querySelector('[data-backfill-poll-target]');
        const status = panel.querySelector('[data-backfill-poll-status]');
        const url = panel.dataset.backfillPollUrl;
        const interval = Number.parseInt(panel.dataset.backfillPollInterval || '5000', 10);
        const activeKey = panel.dataset.backfillPollActiveKey || 'has_active_backfills';
        const activeMessage = panel.dataset.backfillPollActiveMessage || 'Live updates enabled';
        const completeMessage = panel.dataset.backfillPollCompleteMessage || 'Live updates complete';
        const pausedMessage = panel.dataset.backfillPollPausedMessage || 'Live updates paused';

        if (! target || ! url) {
            return;
        }

        panel.dataset.enhanced = 'true';

        let isRefreshing = false;
        let timerId = null;

        const stopPolling = () => {
            if (timerId !== null) {
                window.clearInterval(timerId);
                timerId = null;
            }
        };

        const refreshBackfills = async () => {
            if (isRefreshing) {
                return;
            }

            isRefreshing = true;

            try {
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (! response.ok) {
                    throw new Error('Unable to refresh backfills.');
                }

                const data = await response.json();

                target.innerHTML = data.html;

                if (data[activeKey]) {
                    updateBackfillPollStatus(status, activeMessage, true);

                    return;
                }

                updateBackfillPollStatus(status, completeMessage, false);
                stopPolling();
            } catch (error) {
                updateBackfillPollStatus(status, pausedMessage, false);
                stopPolling();
            } finally {
                isRefreshing = false;
            }
        };

        timerId = window.setInterval(refreshBackfills, Number.isNaN(interval) ? 5000 : interval);
    });
};

const initializeFormControls = (root = document) => {
    initializeSelects(root);
    initializeDates(root);
    initializeBackfillPolling(root);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initializeFormControls());
} else {
    initializeFormControls();
}
document.addEventListener('alpine:init', () => {
    Alpine.magic('initializeFormControls', () => initializeFormControls);
});

Alpine.start();
