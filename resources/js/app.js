
import Alpine from 'alpinejs';
import mask from '@alpinejs/mask';
import flatpickr from 'flatpickr';
import TomSelect from 'tom-select';
import 'flatpickr/dist/flatpickr.css';
import 'tom-select/dist/css/tom-select.css';

window.Alpine = Alpine;

Alpine.plugin(mask);

const selectConfig = (select) => {
    const isMultiple = select.multiple;
    const placeholder = select.dataset.placeholder || '';

    return {
        allowEmptyOption: ! isMultiple,
        closeAfterSelect: ! isMultiple,
        create: false,
        hideSelected: isMultiple,
        maxOptions: 500,
        plugins: isMultiple ? ['remove_button'] : [],
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

const initializeDates = (root = document) => {
    root.querySelectorAll('[data-enhance="date"]').forEach((input) => {
        if (input._flatpickr || input.dataset.enhanced === 'true') {
            return;
        }

        input.dataset.enhanced = 'true';
        flatpickr(input, {
            allowInput: true,
            dateFormat: 'Y-m-d',
            maxDate: input.getAttribute('max') || undefined,
            minDate: input.getAttribute('min') || undefined,
        });
    });
};

const initializeFormControls = (root = document) => {
    initializeSelects(root);
    initializeDates(root);
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
