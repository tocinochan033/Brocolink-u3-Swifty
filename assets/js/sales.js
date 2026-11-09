document.addEventListener('DOMContentLoaded', function() {
    const productSelect  = document.getElementById('product_id');
    const priceInput     = document.getElementById('price');
    const taxInput       = document.getElementById('tax_rate');
    const quantityInput  = document.getElementById('quantity');
    const addProductBtn  = document.getElementById('add-product');
    const productTable   = document.getElementById('product-table').getElementsByTagName('tbody')[0];
    const subtotalSpan   = document.getElementById('subtotal');
    const taxAmountSpan  = document.getElementById('tax-amount');
    const totalSpan      = document.getElementById('total');
    const submitBtn      = document.querySelector('#sale-form button[type="submit"]');

    // Si quieres que el catálogo esté lleno desde el inicio:
    fillProductCatalog();

    // Cuando cambias el precio, cantidad o impuesto, recalcula todo
    [priceInput, taxInput, quantityInput].forEach(input => {
        input.addEventListener('input', updateTotals);
    });

    // Llenar catálogo (ya no filtramos por tipo)
    function fillProductCatalog() {
        productSelect.innerHTML = '<option value="">Seleccionar...</option>';
        allProducts.forEach(prod => {
            const opt = document.createElement('option');
            opt.value = prod.id;
            opt.text  = prod.name + ' ($' + Number(prod.price).toFixed(2) + ')';
            opt.dataset.name = prod.name;
            opt.setAttribute('data-price', prod.price);
            productSelect.appendChild(opt);
        });
        priceInput.value = '';
    }

    productSelect.addEventListener('change', function() {
        const selected = productSelect.selectedOptions[0];
        priceInput.value = selected && selected.getAttribute('data-price') ? selected.getAttribute('data-price') : '';
    });

    addProductBtn.addEventListener('click', async function() {
        const productId = productSelect.value;
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        const productName = selectedOption
            ? selectedOption.dataset.name
            : '';
        const productPrice = parseFloat(priceInput.value);
        const quantity = parseInt(quantityInput.value);
        let taxRate = parseFloat(taxInput.value);

        if (isNaN(taxRate) || taxRate < 0) taxRate = 0.00;

        if (!productId || isNaN(quantity) || quantity < 1 || isNaN(productPrice) || productPrice < 0) {
            alert('Completa todos los campos correctamente.');
            return;
        }

        // Calculo subtotal y impuesto por línea
        const subtotal = productPrice * quantity;
        const taxAmt = subtotal * (taxRate / 100);

        try {
            await saveProductToSession({
                id: productId,
                name: productName,
                price: productPrice,
                quantity: quantity,
                tax_rate: taxRate
            });
        } catch (error) {
            alert('No se pudo agregar el producto. Recarga la página e inténtalo de nuevo.');
            return;
        }

        const rowIndex = productTable.rows.length;
        const row = productTable.insertRow();
        row.setAttribute('data-index', rowIndex);
        row.insertCell().textContent = productName;
        row.insertCell().textContent = `$${productPrice.toFixed(2)}`;
        row.insertCell().textContent = String(quantity);
        row.insertCell().textContent = `${taxRate.toFixed(2)}%`;
        row.insertCell().textContent = `$${(subtotal + taxAmt).toFixed(2)}`;

        const actions = row.insertCell();
        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'DeleteBtn remove-product';
        removeButton.textContent = 'Eliminar';
        actions.appendChild(removeButton);

        [
            ['id', productId],
            ['name', productName],
            ['price', productPrice],
            ['quantity', quantity],
            ['tax_rate', taxRate]
        ].forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `products[${rowIndex}][${name}]`;
            input.value = value;
            actions.appendChild(input);
        });

        updateTotals();
        productSelect.value = '';
        priceInput.value = '';
        taxInput.value = '0.00';
        quantityInput.value = 1;
        submitBtn.disabled = false;
    });

    productTable.addEventListener('click', async function(e) {
        if (e.target.classList.contains('remove-product')) {
            const row = e.target.closest('tr');
            const rowIndex = row.getAttribute('data-index');
            try {
                await removeProductFromSession(rowIndex);
            } catch (error) {
                alert('No se pudo eliminar el producto. Recarga la página e inténtalo de nuevo.');
                return;
            }
            row.remove();
            reindexTableRows();
            updateTotals();
            if (productTable.rows.length === 0) submitBtn.disabled = true;
        }
    });

    function sessionRequest(url, body) {
        const token = document.querySelector('#sale-form input[name="csrf_token"]').value;
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token
            },
            body: JSON.stringify(body)
        }).then(response => {
            if (!response.ok) {
                throw new Error('La solicitud no fue aceptada.');
            }
            return response.json();
        });
    }

    function saveProductToSession(product) {
        return sessionRequest('save_product_session.php', product);
    }

    function removeProductFromSession(index) {
        return sessionRequest('remove_product_session.php', { index: index });
    }

    function reindexTableRows() {
        const rows = productTable.rows;
        for (let i = 0; i < rows.length; i++) {
            rows[i].setAttribute('data-index', i);
            const inputs = rows[i].querySelectorAll('input[type="hidden"]');
            inputs.forEach(input => {
                const name = input.getAttribute('name');
                input.setAttribute('name', name.replace(/\[\d+\]/, `[${i}]`));
            });
        }
    }

    function updateTotals() {
        let subtotal = 0;
        let totalTax = 0;
        let total = 0;

        const rows = productTable.rows;
        for (let i = 0; i < rows.length; i++) {
            const price = parseFloat(rows[i].querySelector('input[name*="[price]"]').value);
            const quantity = parseInt(rows[i].querySelector('input[name*="[quantity]"]').value);
            const taxRate = parseFloat(rows[i].querySelector('input[name*="[tax_rate]"]').value) || 0;

            const lineSubtotal = price * quantity;
            const lineTax = lineSubtotal * (taxRate / 100);

            subtotal += lineSubtotal;
            totalTax += lineTax;
        }

        total = subtotal + totalTax;

        subtotalSpan.textContent = `$${subtotal.toFixed(2)}`;
        taxAmountSpan.textContent = `$${totalTax.toFixed(2)}`;
        totalSpan.textContent = `$${total.toFixed(2)}`;

        // Actualizar inputs hidden
        document.querySelector('input[name="subtotal"]').value = subtotal;
        document.querySelector('input[name="tax_amount"]').value = totalTax;
        document.querySelector('input[name="total"]').value = total;

        // Habilita el botón si hay al menos un producto y los totales tienen sentido
        if (productTable.rows.length > 0 && subtotal >= 0) {
            submitBtn.disabled = false;
        } else {
            submitBtn.disabled = true;
        }
    }

    // Inicialización
    fillProductCatalog();
});
