(() => {
    'use strict';
    const store = window.POSONLYOffline;
    const dialog = document.getElementById('offline-controls');
    const status = document.getElementById('offline-device-status');
    const button = document.getElementById('offline-mode-button');
    let identity;
    let busy = false;
    let ready = false;
    let clearing = false;
    let feedback = null;
    const message = text => { feedback = text; status.textContent = text; };
    async function update() {
        if (!store) { button.disabled = true; return; }
        try {
            const current = await store.context();
            const owns = identity && current.active?.owner === identity;
            const pending = owns ? current.sales.filter(sale => sale.status !== 'synced').length : 0;
            button.textContent = pending ? `Offline mode · ${pending} queued` : 'Offline mode';
            dialog.querySelector('[data-offline="open"]').hidden = !owns || !current.active.enabled;
            dialog.querySelector('[data-offline="enable"]').textContent = owns && current.active.enabled ? 'Refresh saved catalog' : 'Enable on this device';
            dialog.querySelector('[data-offline="enable"]').disabled = busy || !ready;
            dialog.querySelector('[data-offline="sync"]').disabled = busy || !owns || !current.sales.some(sale => sale.status !== 'synced');
            dialog.querySelector('[data-offline="export"]').disabled = busy || !owns || !pending;
            dialog.querySelector('[data-offline="clear"]').disabled = busy || !current.active;
            if (!busy) {
                status.textContent = feedback || (owns && current.snapshot ? `${current.snapshot.tenant.name} · catalog saved ${new Date(current.snapshot.captured_at).toLocaleString()} · ${pending} queued. ${store.fresh(current.snapshot) ? 'Ready for cash sales.' : 'Catalog expired; reconnect to refresh.'}` : 'Offline mode is not enabled for this account.');
            }
        } catch (error) { message(error.message); }
    }
    async function register() {
        if (!('serviceWorker' in navigator) || !window.isSecureContext) { throw new Error('Offline mode needs HTTPS or localhost and a browser with service worker support.'); }
        const registration = await navigator.serviceWorker.register('/service-worker.js', {scope: '/', updateViaCache: 'none'});
        await registration.update();
        const worker = registration.installing || registration.waiting || registration.active;
        if (worker && worker.state !== 'activated') {
            await new Promise((resolve, reject) => {
                const timeout = setTimeout(() => reject(new Error('Offline page could not be installed. Check your connection and try again.')), 20000);
                worker.addEventListener('statechange', () => {
                    if (worker.state === 'activated') { clearTimeout(timeout); resolve(); }
                    if (worker.state === 'redundant') { clearTimeout(timeout); reject(new Error('Offline files could not be saved. Check your connection.')); }
                });
            });
        }
        await navigator.serviceWorker.ready;
        navigator.storage?.persist?.().catch(() => {});
    }
    async function cleanup() {
        if ('serviceWorker' in navigator) {
            const registrations = await navigator.serviceWorker.getRegistrations();
            for (const registration of registrations) {
                if ([registration.active, registration.waiting, registration.installing].some(worker => worker?.scriptURL === `${location.origin}/service-worker.js`)) { await registration.unregister(); }
            }
        }
        if ('caches' in window) { for (const key of await caches.keys()) { if (key.startsWith('posonly-offline-shell-')) { await caches.delete(key); } } }
    }
    button?.addEventListener('click', () => { feedback = null; dialog.showModal(); update(); });
    dialog?.addEventListener('click', async event => {
        const action = event.target.closest('[data-offline]')?.dataset.offline;
        if (!action || busy || action === 'open') { return; }
        if (action === 'close') { dialog.close(); return; }
        busy = true; await update();
        try {
            if (action === 'enable') {
                if (!identity) { throw new Error('Wait for your account to load.'); }
                message('Preparing offline files and checking your current account…');
                await register();
                const snapshot = await store.refreshSnapshot(true, identity);
                if (`${snapshot.tenant.id}:${snapshot.user.id}` !== identity) { throw new Error('Your account changed. Reload before saving offline data.'); }
                message('Saved on this device. Open saved POS to browse inventory or record queued cash sales.');
            }
            if (action === 'sync') {
                const result = await store.sync();
                message(`${result.synced} sales confirmed; ${result.conflicts} need review. Open saved POS for details.`);
                window.dispatchEvent(new Event('posonly:offline-synced'));
            }
            if (action === 'export') { await store.exportQueue(); message('Queue backup downloaded. Keep it private; it contains original cash sale requests.'); }
            if (action === 'clear') { await store.clear(); await cleanup(); message('Saved catalog and confirmed offline receipts cleared from this device.'); }
        } catch (error) { message(error.message); }
        finally { busy = false; const resultMessage = status.textContent; await update(); message(resultMessage); }
    });
    window.addEventListener('posonly:workspace-ready', async event => {
        identity = `${event.detail.tenant_id}:${event.detail.user_id}`; ready = true;
        try { await store.identify(identity); } catch (error) { message(error.message); }
        await update();
        try { const current = await store.context(); if (current.active?.enabled && current.active.owner === identity && 'serviceWorker' in navigator) { await navigator.serviceWorker.register('/service-worker.js', {scope: '/', updateViaCache: 'none'}); } } catch { /* A saved device can continue using its installed public assets. */ }
    });
    window.addEventListener('posonly:offline-changed', update);
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    document.querySelector('form[action="/logout"]')?.addEventListener('submit', async event => {
        if (clearing) { return; }
        event.preventDefault();
        try { await store.clear(true); await cleanup(); clearing = true; event.target.requestSubmit(); }
        catch (error) { dialog.showModal(); message(error.message); }
    });
    update();
})();
