(() => {
    'use strict';
    const store = window.POSONLYOffline;
    const content = document.getElementById('offline-content');
    const notice = document.getElementById('offline-notice');
    const queue = document.getElementById('offline-sales');
    const receiptDialog = document.getElementById('offline-receipt');
    const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
    const money = cents => new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'}).format(cents / 100);
    let current;
    let locationId = 0;
    let tier = 'retail';
    let search = '';
    let discount = '0';
    let cash = '';
    let cart = [];
    let busy = false;
    let cartPermit;
    const note = text => { notice.textContent = text; };
    const writable = () => current?.active?.enabled && store.fresh(current?.snapshot) && current.snapshot.can_write;
    const productById = id => current.snapshot.products.find(product => product.id === Number(id));
    function totals() { return store.quote(current.snapshot, cart, tier, discount); }
    function renderCatalog() {
        const products = current.snapshot.products.filter(product => [product.name, product.sku, product.barcode, product.variant].join(' ').toLowerCase().includes(search.toLowerCase()));
        document.getElementById('offline-products').innerHTML = products.length ? products.slice(0, 200).map(product => {
            const stock = store.available(product, locationId, current.sales);
            const price = tier === 'wholesale' ? product.wholesale_price : product.price;
            const inCart = cart.find(item => item.product_id === product.id)?.quantity || 0;
            return `<button type="button" class="product-card" data-add="${product.id}" ${!writable() || stock <= inCart || price === null ? 'disabled' : ''}><strong>${escape(product.name)}</strong><small>${escape(product.sku)}${product.variant ? ` · ${escape(product.variant)}` : ''}</small><small>${stock} available${inCart ? ` · ${inCart} in cart` : ''}</small><strong class="offline-price">${price === null ? 'No wholesale price' : money(Math.round(Number(price) * 100))}</strong></button>`;
        }).join('') : '<p class="offline-empty">No products match this search or barcode.</p>';
    }
    function renderCart() {
        const area = document.getElementById('offline-cart-lines');
        area.innerHTML = cart.length ? cart.map(item => `<div class="offline-cart-line"><strong>${escape(productById(item.product_id)?.name)}</strong><div class="offline-quantity"><span>${money(Math.round(Number(tier === 'wholesale' ? productById(item.product_id).wholesale_price : productById(item.product_id).price) * 100))} each</span><div><button type="button" data-quantity="${item.product_id}" data-change="-1" aria-label="Remove one ${escape(productById(item.product_id)?.name)}">−</button> <span>${item.quantity}</span> <button type="button" data-quantity="${item.product_id}" data-change="1" aria-label="Add one ${escape(productById(item.product_id)?.name)}">+</button></div></div></div>`).join('') : '<p class="offline-empty">Choose a product or scan its barcode.</p>';
        try {
            const quote = totals();
            document.getElementById('offline-totals').innerHTML = `<div><span>Subtotal</span><strong>${money(quote.subtotal_cents)}</strong></div><div><span>Discount</span><strong>−${money(quote.discount_cents)}</strong></div><div><span>Tax · ${current.snapshot.settings.tax_percent}%</span><strong>${money(quote.tax_cents)}</strong></div><div class="offline-total"><span>Total</span><strong>${money(quote.total_cents)}</strong></div>`;
            document.getElementById('offline-charge').disabled = busy || !writable() || !cart.length;
        } catch (error) { note(error.message); document.getElementById('offline-charge').disabled = true; }
        document.getElementById('offline-clear-cart').disabled = busy || !cart.length;
        renderCatalog();
    }
    function renderSales() {
        const rows = [...current.sales].reverse();
        const awaiting = rows.filter(sale => sale.status !== 'synced');
        const confirmed = rows.filter(sale => sale.status === 'synced');
        const recentConfirmed = confirmed.slice(0, 100);
        const needsReview = awaiting.filter(sale => sale.status === 'conflict').length;
        const counts = `${awaiting.length} awaiting confirmation${needsReview ? ` · ${needsReview} ${needsReview === 1 ? 'needs' : 'need'} review` : ''}. Showing ${recentConfirmed.length} of ${confirmed.length} confirmed receipts${confirmed.length > 100 ? ' (most recent 100)' : ''}. All unconfirmed sales are listed first.`;
        queue.innerHTML = rows.length ? `<p class="offline-description" style="padding:22px;margin:0">${counts}</p>` + [...awaiting, ...recentConfirmed].map(sale => `<div class="offline-sale-row"><div><strong>${escape(sale.receipt?.number || sale.provisional_number)}</strong> <span class="badge">${sale.status === 'synced' ? 'Confirmed' : sale.status === 'conflict' ? 'Needs review' : 'Queued · provisional'}</span><small>${escape(sale.location)} · ${escape(new Date(sale.created_at).toLocaleString())} · ${money(sale.payload.expected.total_cents)}</small>${sale.error ? `<small class="offline-conflict">${escape(sale.error)} Keep the original request. Ask your administrator to reconcile it before retrying.</small>` : ''}</div><button class="button" data-receipt="${escape(sale.id)}">View receipt</button></div>`).join('') : '<p class="offline-empty" style="padding:22px">No cash sales saved on this device for this account.</p>';
    }
    function render() {
        const snapshot = current.snapshot;
        document.getElementById('offline-company').textContent = snapshot ? `${snapshot.tenant.name} · ${snapshot.user.role}` : 'No valid saved catalog';
        document.getElementById('offline-sync').disabled = busy || !current.active?.owner || !current.sales.some(sale => sale.status !== 'synced');
        document.getElementById('offline-refresh').disabled = busy || !current.active?.enabled;
        document.getElementById('offline-export').disabled = busy || !current.sales.some(sale => sale.status !== 'synced');
        renderSales();
        if (!current.active?.enabled || !store.fresh(snapshot)) {
            content.innerHTML = '<p class="offline-empty">Open your online workspace and choose Offline mode to save or refresh the catalog for this trusted device. Previously queued receipts remain below; sign in to their original account to sync them.</p>' + (cart.length ? `<p class="offline-description">Your unsaved cart has ${cart.reduce((sum, item) => sum + item.quantity, 0)} items. Clear it before refreshing the catalog or syncing. This does not delete queued receipts.</p><button id="offline-clear-cart" class="button" type="button" ${busy ? 'disabled' : ''}>Clear unsaved cart</button>` : '');
            return;
        }
        if (!snapshot.locations.some(location => location.id === locationId)) { locationId = snapshot.locations[0]?.id || 0; }
        content.innerHTML = `<div class="pos-layout"><section class="offline-catalog"><div class="offline-filters"><label>Stock location<select id="saved-location">${snapshot.locations.map(location => `<option value="${location.id}" ${location.id === locationId ? 'selected' : ''}>${escape(location.name)}</option>`).join('')}</select></label><label>Search or scan barcode<input id="saved-search" type="search" placeholder="Name, SKU, or barcode" value="${escape(search)}" autocomplete="off"></label><label>Price tier<select id="saved-tier"><option value="retail" ${tier === 'retail' ? 'selected' : ''}>Retail</option><option value="wholesale" ${tier === 'wholesale' ? 'selected' : ''}>Wholesale</option></select></label></div><div class="product-grid" id="offline-products"></div></section><section class="panel offline-cart"><h2>Cash checkout</h2><p class="offline-description" style="margin:10px 0">Walk-in customer · ${snapshot.cash_sessions.find(session => session.location_id === locationId) ? 'Saved open cash shift' : 'No saved open cash shift'}</p><div class="offline-cart-lines" id="offline-cart-lines"></div><form id="offline-cash-form"><label>Discount (%)<input id="saved-discount" type="number" min="0" max="100" step="0.01" value="${escape(discount)}"></label><div class="offline-totals" id="offline-totals"></div><label>Cash received (₱)<input id="saved-cash" type="number" min="0" max="1000000000" step="0.01" required value="${escape(cash)}" inputmode="decimal"></label><button id="offline-charge" class="button primary" type="submit">Record queued cash sale</button><button id="offline-clear-cart" class="button" type="button" style="margin-top:10px">Clear cart</button></form></section></div>`;
        renderCart();
    }
    async function load(preserveMessage = false) {
        const next = await store.context();
        if (current?.active?.owner !== next.active?.owner) { receiptDialog.close(); receiptDialog.querySelector('.offline-receipt-content').replaceChildren(); }
        if (current?.active?.owner !== next.active?.owner || (cart.length && cartPermit !== next.snapshot?.permit)) { cart = []; cash = ''; cartPermit = null; }
        current = next;
        if (receiptDialog.open) { const sale = current.sales.find(sale => sale.id === receiptDialog.dataset.saleId); if (sale) { showReceipt(sale); } else { receiptDialog.close(); receiptDialog.querySelector('.offline-receipt-content').replaceChildren(); } }
        render();
        if (!preserveMessage) {
            note(current.snapshot ? `Saved catalog · ${new Date(current.snapshot.captured_at).toLocaleString()} · ${writable() ? 'cash queue available until ' + new Date(current.snapshot.expires_at).toLocaleString() : 'Reconnect to refresh access before recording sales.'}` : 'No saved catalog is available. Queued cash records are retained for their original account.');
        }
    }
    function add(id) {
        if (busy || !writable()) { return; }
        const product = productById(id);
        const item = cart.find(item => item.product_id === product.id);
        if ((item?.quantity || 0) >= store.available(product, locationId, current.sales)) { note('This cart already uses the saved available stock.'); return; }
        if (tier === 'wholesale' && product.wholesale_price === null) { note('This SKU has no wholesale price.'); return; }
        cartPermit = current.snapshot.permit;
        if (item) { item.quantity += 1; } else { cart.push({product_id: product.id, quantity: 1}); }
        renderCart();
    }
    content.addEventListener('click', event => {
        const target = event.target.closest('button');
        if (!target || busy) { return; }
        if (target.dataset.add) { add(Number(target.dataset.add)); }
        if (target.dataset.quantity) {
            const id = Number(target.dataset.quantity);
            if (target.dataset.change === '1') { add(id); }
            else { const item = cart.find(item => item.product_id === id); item.quantity -= 1; cart = cart.filter(item => item.quantity > 0); renderCart(); }
        }
        if (target.id === 'offline-clear-cart') { cart = []; cash = ''; cartPermit = null; render(); }
    });
    content.addEventListener('input', event => {
        if (event.target.id === 'saved-search') { search = event.target.value; renderCatalog(); }
        if (event.target.id === 'saved-discount') { discount = event.target.value; renderCart(); }
        if (event.target.id === 'saved-cash') { cash = event.target.value; }
    });
    content.addEventListener('keydown', event => {
        if (event.target.id !== 'saved-search' || event.key !== 'Enter') { return; }
        event.preventDefault();
        const matches = current.snapshot.products.filter(product => product.barcode && product.barcode === search.trim());
        if (matches.length === 1) { add(matches[0].id); search = ''; event.target.value = ''; renderCatalog(); }
        else { note(matches.length ? 'This barcode matches more than one SKU. Select the exact product.' : 'No exact saved barcode matches. Search or select a product.'); }
    });
    content.addEventListener('change', event => {
        if (event.target.id === 'saved-location') {
            if (cart.length) { event.target.value = locationId; note('Clear or complete this cart before changing locations.'); return; }
            locationId = Number(event.target.value); render();
        }
        if (event.target.id === 'saved-tier') {
            if (cart.length) { event.target.value = tier; note('Clear or complete this cart before changing price tiers.'); return; }
            tier = event.target.value; render();
        }
    });
    content.addEventListener('submit', async event => {
        if (event.target.id !== 'offline-cash-form') { return; }
        event.preventDefault();
        if (busy) { return; }
        busy = true; renderCart();
        try {
            const sale = await store.enqueue({locationId, items: cart, tier, discount, cash, snapshotPermit: cartPermit});
            cart = []; cash = ''; discount = '0'; cartPermit = null;
            note('Cash sale saved on this device. Its receipt is provisional until synchronization succeeds.');
            await load(true); showReceipt(sale);
        } catch (error) { note(error.message); }
        finally { busy = false; render(); }
    });
    function showReceipt(sale) {
        receiptDialog.dataset.saleId = sale.id;
        const expected = sale.payload.expected;
        const paid = Math.round(sale.payload.payments[0].amount * 100);
        receiptDialog.querySelector('.offline-receipt-content').innerHTML = `<h2 id="offline-receipt-title">${sale.status === 'synced' ? 'Confirmed cash sale' : 'Provisional cash receipt'}</h2><div class="receipt-paper"><h3>${escape(sale.company)}</h3><p><strong>${escape(sale.receipt?.number || sale.provisional_number)}</strong><br>${sale.status === 'synced' ? 'Server confirmed · ' + escape(new Date(sale.receipt.created_at).toLocaleString()) : 'Saved locally · not yet confirmed'}<br>Recorded ${escape(new Date(sale.created_at).toLocaleString())}<br>${escape(sale.location)} · ${escape(sale.payload.price_tier)}</p><table><tbody>${sale.labels.map(item => `<tr><td>${escape(item.name)}<br>${escape(item.sku)} · ${item.quantity} × ${money(item.unit_price_cents)}</td><td>${money(item.unit_price_cents * item.quantity)}</td></tr>`).join('')}</tbody></table><div class="offline-totals"><div><span>Subtotal</span><strong>${money(expected.subtotal_cents)}</strong></div><div><span>Discount</span><strong>−${money(expected.discount_cents)}</strong></div><div><span>Tax</span><strong>${money(expected.tax_cents)}</strong></div><div class="offline-total"><span>Total</span><strong>${money(expected.total_cents)}</strong></div><div><span>Cash received</span><strong>${money(paid)}</strong></div><div><span>Change</span><strong>${money(paid - expected.total_cents)}</strong></div></div><p>${escape(sale.receipt_footer)}<br>Payment record · not a fiscal invoice.</p><p>${sale.status === 'synced' ? 'Open the online workspace to print the company-branded final receipt.' : 'Stock has not been deducted centrally. Keep this receipt until the server confirms the original cash sale.'}</p></div><div class="form-actions"><button class="button" data-close-receipt>Close receipt</button></div>`;
        if (!receiptDialog.open) { receiptDialog.showModal(); }
    }
    queue.addEventListener('click', event => { const id = event.target.closest('[data-receipt]')?.dataset.receipt; if (id) { showReceipt(current.sales.find(sale => sale.id === id)); } });
    receiptDialog.addEventListener('click', event => { if (event.target.closest('[data-close-receipt]') || event.target === receiptDialog) { receiptDialog.close(); } });
    async function action(callback) {
        if (busy) { return; }
        if (cart.length) { note('Complete or clear this cart before refreshing or syncing the saved catalog.'); return; }
        busy = true; render();
        try { note('Checking your account and saved requests…'); await callback(); }
        catch (error) { note(error.message); }
        finally { busy = false; await load(true); }
    }
    document.getElementById('offline-sync').addEventListener('click', () => action(async () => { const result = await store.sync(); note(`${result.synced} cash sales confirmed. ${result.conflicts} need administrator review. No original amounts were changed.`); }));
    document.getElementById('offline-refresh').addEventListener('click', () => action(async () => { await store.refreshSnapshot(); note('Saved catalog refreshed. Review current stock and prices before starting the next cart.'); }));
    document.getElementById('offline-export').addEventListener('click', () => action(async () => { await store.exportQueue(); note('Queue backup downloaded. Keep the file private and keep the original queue on this device.'); }));
    window.addEventListener('posonly:offline-changed', () => { if (!busy) { load().catch(error => note(error.message)); } });
    window.addEventListener('online', () => note('Connection restored. Choose Sync queued sales to confirm the original cash requests.'));
    window.addEventListener('offline', () => note('Connection interrupted. Queued cash sales remain on this device; sync them when reconnected.'));
    setInterval(() => { if (current?.snapshot && !store.fresh(current.snapshot) && content.querySelector('#offline-cash-form')) { render(); note('The saved catalog expired. Reconnect and refresh it. Queued cash sales remain preserved.'); } }, 30000);
    load().catch(error => note(error.message));
})();
