document.querySelectorAll('[data-transaction-form]').forEach((form) => {
    let nextItemIndex = 0;

    const rows = () => [...form.querySelectorAll('[data-item-row]')];
    const updateTotals = () => {
        const itemTotal = rows().reduce((sum, row) => {
            const quantity = Number(row.querySelector('.item-quantity')?.value ?? 1);
            const price = Number(row.querySelector('.item-unit-price')?.value ?? 0);
            const subtotal = quantity * price;
            const subtotalLabel = row.querySelector('[data-subtotal]');
            if (subtotalLabel) {
                subtotalLabel.textContent = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(subtotal);
            }

            return sum + subtotal;
        }, 0);
        const directAmount = Number(form.querySelector('[name="income_amount"]')?.value ?? 0);
        const isIncomeDirect = form.dataset.type === 'income'
            && form.querySelector('[data-income-mode]')?.value === 'direct';
        const total = isIncomeDirect ? directAmount : itemTotal;
        const totalLabel = form.querySelector('[data-total]');
        if (totalLabel) {
            totalLabel.textContent = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(total);
        }
    };

    const filterSubcategories = (row) => {
        const categoryId = row.querySelector('[data-category]')?.value;
        const select = row.querySelector('[data-subcategory]');
        if (!select) {
            return;
        }
        [...select.options].forEach((option) => {
            if (option.value === '') {
                option.hidden = false;
                return;
            }
            const matches = option.dataset.parent === categoryId;
            option.hidden = !matches;
            option.disabled = !matches;
            if (!matches && option.selected) {
                select.value = '';
            }
        });
    };

    const reindexRows = () => {
        rows().forEach((row, index) => {
            row.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace(/items\[\d+\]/, `items[${index}]`);
            });
        });
        nextItemIndex = rows().length;
    };

    const setIncomeMode = (mode) => {
        const directSection = form.querySelector('[data-direct-income]');
        const itemSection = form.querySelector('[data-items-section]');
        if (!directSection || !itemSection) {
            return;
        }
        form.querySelector('[data-income-mode]').value = mode;
        directSection.classList.toggle('hidden', mode !== 'direct');
        itemSection.classList.toggle('hidden', mode !== 'items');
        directSection.querySelectorAll('input').forEach((input) => { input.disabled = mode !== 'direct'; });
        itemSection.querySelectorAll('input, select, textarea').forEach((input) => { input.disabled = mode !== 'items'; });
        itemSection.querySelectorAll('button').forEach((button) => { button.disabled = mode !== 'items'; });
        updateTotals();
    };

    const addRow = () => {
        const firstRow = rows()[0];
        if (!firstRow) {
            return;
        }
        const row = firstRow.cloneNode(true);
        row.querySelectorAll('input').forEach((field) => {
            field.value = field.type === 'hidden' && field.name.endsWith('[quantity]') ? '1' : '';
        });
        row.querySelectorAll('select').forEach((field) => { field.selectedIndex = 0; });
        const subtotal = row.querySelector('[data-subtotal]');
        if (subtotal) {
            subtotal.textContent = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(0);
        }
        row.querySelectorAll('[name]').forEach((field) => {
            field.name = field.name.replace(/items\[\d+\]/, `items[${nextItemIndex}]`);
        });
        nextItemIndex += 1;
        form.querySelector('[data-items-container]').append(row);
        filterSubcategories(row);
        updateTotals();
    };

    form.querySelectorAll('[data-add-item]').forEach((button) => button.addEventListener('click', addRow));
    form.addEventListener('click', (event) => {
        if (event.target.closest('[data-remove-item]')) {
            event.target.closest('[data-item-row]').remove();
            reindexRows();
            updateTotals();
        }
        const modeButton = event.target.closest('[data-mode]');
        if (modeButton) {
            setIncomeMode(modeButton.dataset.mode);
        }
    });
    form.addEventListener('input', updateTotals);
    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-category]')) {
            filterSubcategories(event.target.closest('[data-item-row]'));
        }
    });

    rows().forEach(filterSubcategories);
    reindexRows();
    if (form.dataset.type === 'income') {
        setIncomeMode(form.querySelector('[data-income-mode]').value);
    }
    updateTotals();
});
