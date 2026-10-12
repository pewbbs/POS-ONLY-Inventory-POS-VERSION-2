(() => {
    'use strict';
    const DATABASE = 'posonly-offline-v1';
    const MAX_QUEUE = 500;
    let connection;
    const channel = 'BroadcastChannel' in window ? new BroadcastChannel(DATABASE) : null;
    const changed = () => { window.dispatchEvent(new Event('posonly:offline-changed')); channel?.postMessage('changed'); };
    channel?.addEventListener('message', () => window.dispatchEvent(new Event('posonly:offline-changed')));
    navigator.serviceWorker?.addEventListener('message', event => { if (event.data?.type === 'posonly-signed-out') { changed(); } });
    const ownerOf = snapshot => `${snapshot.tenant.id}:${snapshot.user.id}`;
    const uuid = () => crypto.randomUUID ? crypto.randomUUID() : [...crypto.getRandomValues(new Uint8Array(24))].map(value => value.toString(16).padStart(2, '0')).join('');
    const cents = value => Math.round((Number(value) + Number.EPSILON) * 100);
    const remaining = sale => sale.status !== 'synced';
    const onlineRequests = (records, owner = null) => records.filter(record => record.kind === 'online-checkout' && (!owner || record.owner === owner)).sort((a, b) => a.saved_at.localeCompare(b.saved_at));
    const requestValue = request => new Promise((resolve, reject) => { request.onsuccess = () => resolve(request.result); request.onerror = () => reject(request.error); });
    async function database() {
        if (connection) { return connection; }
        connection = await new Promise((resolve, reject) => {
            const request = indexedDB.open(DATABASE, 1);
            request.onupgradeneeded = () => ['device', 'snapshots', 'sales', 'locks'].forEach(name => request.result.createObjectStore(name, {keyPath: 'id'}));
            request.onsuccess = () => { request.result.onversionchange = () => { request.result.close(); connection = null; }; resolve(request.result); };
            request.onerror = () => reject(new Error('This browser cannot save offline data. Allow site storage and try again.'));
            request.onblocked = () => reject(new Error('Close other POSONLY tabs before updating offline storage.'));
        });
        return connection;
    }
    async function transaction(names, mode, operation) {
        const db = await database();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(names, mode);
            let result;
            let failure;
            tx.oncomplete = () => resolve(result);
            tx.onerror = () => reject(failure || tx.error || new Error('Offline data could not be saved.'));
            tx.onabort = () => reject(failure || tx.error || new Error('Offline data could not be saved.'));
            Promise.resolve(operation(tx)).then(value => { result = value; }).catch(error => { failure = error; try { tx.abort(); } catch { reject(error); } });
        });
    }
    async function context() {
        return transaction(['device', 'snapshots', 'sales'], 'readonly', async tx => {
            const active = await requestValue(tx.objectStore('device').get('active'));
            if (!active?.owner) { return {active: null, snapshot: null, sales: [], onlinePending: null, onlineRequests: []}; }
            const [snapshot, all, device] = await Promise.all([requestValue(tx.objectStore('snapshots').get(active.owner)), requestValue(tx.objectStore('sales').getAll()), requestValue(tx.objectStore('device').getAll())]);
            const pending = onlineRequests(device, active.owner);
            return {active, snapshot, sales: all.filter(sale => sale.owner === active.owner).sort((a, b) => a.created_at.localeCompare(b.created_at)), onlinePending: pending[0] || null, onlineRequests: pending};
        });
    }
    async function pendingOnline(owner) {
        return transaction(['device'], 'readonly', async tx => onlineRequests(await requestValue(tx.objectStore('device').getAll()), owner)[0] || null);
    }
    async function reserveOnline(owner, envelope, migrating = false) {
        if (`${envelope.tenantId}:${envelope.userId}` !== owner || !envelope.payload?.idempotency_key || !Array.isArray(envelope.payload.items) || !Array.isArray(envelope.payload.payments) || !envelope.totals) { throw new Error('The original checkout could not be saved on this device. No request was sent.'); }
        let created = false;
        const record = await transaction(['device'], 'readwrite', async tx => {
            const store = tx.objectStore('device');
            const pending = onlineRequests(await requestValue(store.getAll()), owner);
            if (await requestValue(store.get(`online-resolved:${owner}:${envelope.payload.idempotency_key}`))) {
                if (migrating) { return null; }
                throw new Error('This original checkout was already resolved in another tab. Refresh the workspace before starting a new sale.');
            }
            const existing = pending.find(record => record.payload.idempotency_key === envelope.payload.idempotency_key);
            if (existing) {
                if (JSON.stringify(existing.payload) !== JSON.stringify(envelope.payload)) { throw new Error('This checkout key already protects a different original request. Check its sale status first.'); }
                return existing;
            }
            if (!migrating && pending.length) { throw new Error('Another checkout on this device still needs confirmation. Check the original sale before starting another checkout.'); }
            const result = {...JSON.parse(JSON.stringify(envelope)), id: `online:${owner}:${envelope.payload.idempotency_key}`, kind: 'online-checkout', owner, saved_at: new Date().toISOString()};
            store.add(result);
            created = true;
            return result;
        });
        if (created) { changed(); }
        return record;
    }
    async function clearOnline(owner, key) {
        await transaction(['device'], 'readwrite', async tx => {
            const store = tx.objectStore('device');
            const record = await requestValue(store.get(`online:${owner}:${key}`));
            if (record?.owner === owner && record.payload.idempotency_key === key) { store.delete(record.id); }
            store.put({id: `online-resolved:${owner}:${key}`, kind: 'online-resolution', owner, key, resolved_at: new Date().toISOString()});
        });
        changed();
    }
    async function cashSessionBlock(owner, sessionId, locationId) {
        return transaction(['device', 'sales'], 'readonly', async tx => {
            const [device, sales] = await Promise.all([requestValue(tx.objectStore('device').getAll()), requestValue(tx.objectStore('sales').getAll())]);
            if (sales.some(sale => sale.owner === owner && remaining(sale) && Number(sale.payload.expected.cash_session_id) === Number(sessionId))) { return 'Sync the queued cash sales for this shift before closing it. Their cash must be confirmed in the original drawer.'; }
            if (onlineRequests(device, owner).some(record => Number(record.payload.location_id) === Number(locationId))) { return 'An online checkout at this location still needs confirmation. Check that original sale before closing this cash shift.'; }
            return null;
        });
    }
    async function identify(owner) {
        await transaction(['device', 'snapshots'], 'readwrite', async tx => {
            const active = await requestValue(tx.objectStore('device').get('active'));
            if (active?.owner === owner) { return; }
            if (active?.owner) { tx.objectStore('snapshots').delete(active.owner); }
            tx.objectStore('device').put({id: 'active', owner, enabled: false, revision: uuid(), refresh_generation: 0});
        });
        changed();
    }
    async function saveSnapshot(snapshot, enabled, request) {
        if (snapshot.version !== 1 || !snapshot.tenant?.id || !snapshot.user?.id || !Array.isArray(snapshot.products) || !Array.isArray(snapshot.locations) || !snapshot.permit || !Number.isFinite(Date.parse(snapshot.expires_at))) { throw new Error('The saved catalog response could not be verified.'); }
        const owner = ownerOf(snapshot);
        await transaction(['device', 'snapshots', 'sales'], 'readwrite', async tx => {
            const active = await requestValue(tx.objectStore('device').get('active'));
            if (!active || active.owner !== owner || active.revision !== request.revision || active.refresh_generation !== request.generation) { throw new Error('Your account or another catalog refresh changed. Retry from the current workspace.'); }
            if (!enabled && !active.enabled) { throw new Error('Enable offline mode for this account first.'); }
            const previous = await requestValue(tx.objectStore('snapshots').get(owner));
            if (previous && Date.parse(previous.captured_at) > Date.parse(snapshot.captured_at)) { throw new Error('A newer catalog is already saved.'); }
            const sales = await requestValue(tx.objectStore('sales').getAll());
            sales.filter(sale => sale.owner === owner && sale.status === 'synced' && request.acknowledgedIds.includes(sale.id)).forEach(sale => tx.objectStore('sales').put({...sale, stock_acknowledged: true}));
            tx.objectStore('snapshots').put({...snapshot, id: owner, clock_offset: Date.parse(snapshot.captured_at) - Date.now()});
            tx.objectStore('device').put({...active, enabled: true});
        });
        changed();
        return owner;
    }
    const serverNow = snapshot => Date.now() + (snapshot?.clock_offset || 0);
    const fresh = snapshot => Boolean(snapshot && serverNow(snapshot) <= Date.parse(snapshot.expires_at));
    function available(product, locationId, sales) {
        const stock = product.stocks.find(stock => Number(stock.location_id) === Number(locationId));
        return Math.max(0, Number(stock?.available_quantity || 0) - sales.filter(sale => sale.payload.location_id === Number(locationId) && (remaining(sale) || !sale.stock_acknowledged)).reduce((sum, sale) => sum + (sale.payload.items.find(item => item.product_id === product.id)?.quantity || 0), 0));
    }
    function quote(snapshot, items, tier, discountPercent) {
        const discountBasis = Math.round(Number(discountPercent) * 100);
        const taxBasis = Math.round(Number(snapshot.settings.tax_percent) * 100);
        if (!Number.isInteger(discountBasis) || discountBasis < 0 || discountBasis > 10000 || Math.abs(Number(discountPercent) * 100 - discountBasis) > 0.00001) { throw new Error('Use a discount from 0 to 100 with up to two decimal places.'); }
        const priced = items.map(item => {
            const product = snapshot.products.find(product => product.id === Number(item.product_id));
            const price = tier === 'wholesale' ? product?.wholesale_price : product?.price;
            if (!product || price === null || price === undefined || !Number.isInteger(item.quantity) || item.quantity < 1 || item.quantity > 100000) { throw new Error('Check the selected product, price tier, and quantity.'); }
            return {product_id: product.id, unit_price_cents: cents(price), quantity: item.quantity, name: product.name, sku: product.sku};
        });
        const subtotal = priced.reduce((sum, item) => sum + item.unit_price_cents * item.quantity, 0);
        if (!Number.isSafeInteger(subtotal * Math.max(discountBasis, taxBasis, 1))) { throw new Error('This sale exceeds the offline amount limit. Use the online workspace.'); }
        const discount = Math.floor((subtotal * discountBasis + 5000) / 10000);
        const tax = Math.floor(((subtotal - discount) * taxBasis + 5000) / 10000);
        return {items: priced, subtotal_cents: subtotal, discount_cents: discount, tax_cents: tax, total_cents: subtotal - discount + tax};
    }
    function assertNoUncertainOnlineSale(owner) {
        try {
            const pending = JSON.parse(sessionStorage.getItem('stockroom-pending-sale') || 'null');
            if (pending && `${pending.tenantId}:${pending.userId}` === owner) { throw new Error('An online checkout still needs confirmation. Reconnect and check that original sale before recording an offline sale.'); }
        } catch (failure) { if (!(failure instanceof SyntaxError)) { throw failure; } }
    }
    async function enqueue({locationId, items, tier, discount, cash, snapshotPermit}) {
        const sale = await transaction(['device', 'snapshots', 'sales'], 'readwrite', async tx => {
            const active = await requestValue(tx.objectStore('device').get('active'));
            const snapshot = active?.enabled ? await requestValue(tx.objectStore('snapshots').get(active.owner)) : null;
            if (!fresh(snapshot) || !snapshot.can_write || !['admin', 'manager', 'cashier'].includes(snapshot.user.role)) { throw new Error('Reconnect and refresh this device before recording a cash sale.'); }
            if (snapshot.permit !== snapshotPermit) { throw new Error('The saved catalog changed. Review your cart again.'); }
            if (onlineRequests(await requestValue(tx.objectStore('device').getAll()), active.owner).length) { throw new Error('An online checkout on this device still needs confirmation. Reconnect and check the original sale before recording an offline sale.'); }
            assertNoUncertainOnlineSale(active.owner);
            const sales = (await requestValue(tx.objectStore('sales').getAll())).filter(sale => sale.owner === active.owner);
            if (sales.filter(remaining).length >= MAX_QUEUE) { throw new Error('Sync the queued sales before recording more.'); }
            if (!snapshot.locations.some(location => location.id === Number(locationId)) || !items.length || items.length > 100 || !['retail', 'wholesale'].includes(tier) || new Set(items.map(item => item.product_id)).size !== items.length) { throw new Error('Check this cart and location.'); }
            items.forEach(item => {
                const product = snapshot.products.find(product => product.id === item.product_id);
                if (!product || item.quantity > available(product, locationId, sales)) { throw new Error('The saved available stock cannot cover this cart. Sync and check stock.'); }
            });
            const totals = quote(snapshot, items, tier, discount);
            const paid = cents(cash);
            if (!Number.isSafeInteger(paid) || paid < totals.total_cents || paid > 100000000000 || Number(cash) < 0 || Math.abs(Number(cash) * 100 - paid) > 0.00001) { throw new Error('Enter enough cash received, with up to two decimal places.'); }
            const id = uuid();
            const recordedAt = new Date(serverNow(snapshot)).toISOString();
            const payload = {
                location_id: Number(locationId), price_tier: tier,
                items: items.map(item => ({product_id: item.product_id, quantity: item.quantity})),
                discount_percent: Number(discount), tax_percent: snapshot.settings.tax_percent,
                payments: [{method: 'cash', amount: paid / 100}], idempotency_key: `offline-${id}`,
                permit: snapshot.permit, recorded_at: recordedAt,
                expected: {items: totals.items.map(({product_id, unit_price_cents}) => ({product_id, unit_price_cents})), subtotal_cents: totals.subtotal_cents, discount_cents: totals.discount_cents, tax_cents: totals.tax_cents, total_cents: totals.total_cents, cash_session_id: snapshot.cash_sessions.find(session => session.location_id === Number(locationId))?.id || null},
            };
            const result = {id, owner: active.owner, status: 'pending', created_at: recordedAt, provisional_number: `OFF-${id.slice(0, 8).toUpperCase()}`, company: snapshot.tenant.name, location: snapshot.locations.find(location => location.id === Number(locationId)).name, receipt_footer: snapshot.settings.receipt_footer, labels: totals.items, payload};
            tx.objectStore('sales').add(result);
            return result;
        });
        changed();
        return sale;
    }
    async function api(path, body) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const headers = {Accept: 'application/json'};
            if (body) {
                headers['Content-Type'] = 'application/json';
                const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
                if (cookie) { headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)); }
            }
            const response = await fetch(`/api/offline/${path}`, {method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers, ...(body ? {body: JSON.stringify(body)} : {}), signal: controller.signal});
            const result = await response.json().catch(() => ({}));
            if (!response.ok) { const error = new Error(result.message || 'This request could not be confirmed. Reconnect and check your account.'); error.status = response.status; throw error; }
            return result;
        } catch (error) {
            if (error.name === 'AbortError' || error instanceof TypeError) { throw new Error('Connection interrupted. The original queued sales stay on this device. Reconnect and retry synchronization.'); }
            throw error;
        } finally { clearTimeout(timeout); }
    }
    async function refreshSnapshot(enabled = false, expectedOwner = null) {
        const request = await transaction(['device', 'sales'], 'readwrite', async tx => {
            const active = await requestValue(tx.objectStore('device').get('active'));
            if (!active?.owner || (expectedOwner && active.owner !== expectedOwner)) { throw new Error('Open your current workspace before refreshing offline data.'); }
            const sales = await requestValue(tx.objectStore('sales').getAll());
            const generation = (active.refresh_generation || 0) + 1;
            tx.objectStore('device').put({...active, refresh_generation: generation});
            return {owner: active.owner, revision: active.revision, generation, acknowledgedIds: sales.filter(sale => sale.owner === active.owner && sale.status === 'synced').map(sale => sale.id)};
        });
        try {
            const snapshot = await api('snapshot');
            if (ownerOf(snapshot) !== request.owner) { await invalidate(request.owner); throw new Error('Your signed-in company changed. Open its workspace before saving this device.'); }
            await saveSnapshot(snapshot, enabled, request);
            return snapshot;
        } catch (error) {
            if ([401, 403, 419].includes(error.status)) { await invalidate(request.owner); }
            throw error;
        }
    }
    async function invalidate(owner) {
        await transaction(['snapshots', 'device'], 'readwrite', async tx => {
            tx.objectStore('snapshots').delete(owner);
            const active = await requestValue(tx.objectStore('device').get('active'));
            if (active?.owner === owner) { tx.objectStore('device').put({...active, enabled: false, revision: uuid(), refresh_generation: 0}); }
        });
        changed();
    }
    async function lock(owner, token, release = false) {
        return transaction(['locks'], 'readwrite', async tx => {
            const store = tx.objectStore('locks');
            const previous = await requestValue(store.get(owner));
            if (release) { if (previous?.token === token) { store.delete(owner); } return true; }
            if (previous && previous.token !== token && previous.expires_at > Date.now()) { return false; }
            store.put({id: owner, token, expires_at: Date.now() + 60000});
            return true;
        });
    }
    async function sync() {
        const current = await context();
        if (!current.active?.owner) { throw new Error('Enable offline mode for this account first.'); }
        const owner = current.active.owner;
        const token = uuid();
        if (!await lock(owner, token)) { throw new Error('Another tab is syncing this account. Wait for it to finish.'); }
        let synced = 0;
        let conflicts = 0;
        try {
            const snapshot = await api('snapshot');
            if (ownerOf(snapshot) !== owner) { await invalidate(owner); throw new Error('Sign in to the original company account before syncing these sales.'); }
            for (const sale of current.sales.filter(remaining)) {
                if (!await lock(owner, token)) { throw new Error('Another tab took over synchronization. Retry later.'); }
                try {
                    const receipt = await api('sales', sale.payload);
                    if (!receipt.id || !receipt.number || receipt.total !== sale.payload.expected.total_cents / 100) { throw new Error('The receipt response could not be confirmed. Retry the original queued sale.'); }
                    await transaction(['sales'], 'readwrite', tx => tx.objectStore('sales').put({...sale, status: 'synced', error: null, stock_acknowledged: false, receipt: {id: receipt.id, number: receipt.number, created_at: receipt.created_at, offline: receipt.offline}}));
                    synced += 1;
                    changed();
                } catch (error) {
                    if ([401, 403, 419, 423].includes(error.status)) { await invalidate(owner); throw error; }
                    if ([404, 409, 422].includes(error.status)) {
                        await transaction(['sales'], 'readwrite', tx => tx.objectStore('sales').put({...sale, status: 'conflict', error: error.message}));
                        conflicts += 1; changed(); continue;
                    }
                    throw error;
                }
            }
            await refreshSnapshot(true, owner);
            return {synced, conflicts};
        } catch (error) {
            if ([401, 403, 419].includes(error.status)) { await invalidate(owner); }
            throw error;
        } finally { await lock(owner, token, true); }
    }
    async function clear(signingOut = false) {
        await transaction(['device', 'snapshots', 'sales'], 'readwrite', async tx => {
            const sales = await requestValue(tx.objectStore('sales').getAll());
            const device = tx.objectStore('device');
            const requests = onlineRequests(await requestValue(device.getAll()));
            if (!signingOut && (sales.some(remaining) || requests.length)) { throw new Error('Unconfirmed sales are still on this device. Sign in to their original account and confirm them before clearing. Download a backup if you need help resolving them.'); }
            if (signingOut) { device.delete('active'); } else { device.clear(); }
            tx.objectStore('snapshots').clear();
            if (signingOut) { sales.filter(sale => !remaining(sale)).forEach(sale => tx.objectStore('sales').delete(sale.id)); }
            else { tx.objectStore('sales').clear(); }
        });
        changed();
    }
    async function exportQueue() {
        const current = await context();
        if (!current.active?.owner) { throw new Error('No saved account is selected.'); }
        const blob = new Blob([JSON.stringify({format: 'posonly-offline-backup-v1', owner: current.active.owner, exported_at: new Date().toISOString(), sales: current.sales.filter(remaining), online_checkouts: current.onlineRequests}, null, 2)], {type: 'application/json'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a'); link.href = url; link.download = `posonly-offline-${new Date().toISOString().slice(0, 10)}.json`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    window.POSONLYOffline = {context, identify, pendingOnline, reserveOnline, clearOnline, cashSessionBlock, saveSnapshot, refreshSnapshot, serverNow, fresh, available, quote, enqueue, sync, clear, exportQueue};
})();
