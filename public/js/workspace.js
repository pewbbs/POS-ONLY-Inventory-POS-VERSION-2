(() => {
    'use strict';

    const view = document.getElementById('workspace-view');
    const modal = document.getElementById('workspace-modal');
    const modalContent = document.getElementById('modal-content');
    const locationSelect = document.getElementById('location-select');
    const sections = {
        overview: ['Overview', 'Sales and stock for the selected location.'],
        pos: ['Point of sale', 'Add products, review the cart, and record payment.'],
        inventory: ['Inventory', 'Manage products and stock at the selected location.'],
        channels: ['Channels', 'Manage simulated sales channels and shared stock.'],
        purchases: ['Purchasing', 'Create purchase orders and record supplier deliveries.'],
        customers: ['Customers', 'Manage customer details and review purchase history.'],
        reports: ['Reports', 'Review sales, returns, and financial totals.'],
        team: ['Team & access', 'Manage staff accounts, roles, and workspace access.'],
        activity: ['Activity log', 'Review successful workspace actions across locations.'],
        monitoring: ['System health', 'Review system checks, background tasks, and incidents.'],
        billing: ['Billing & plans', 'Manage your subscription, invoices, and payment records.'],
        settings: ['Settings', 'Manage company details, receipts, and stock locations.'],
    };
    const state = {
        data: null,
        section: Object.hasOwn(sections, document.body.dataset.section) ? document.body.dataset.section : 'overview',
        location: null,
        search: '',
        category: 'All products',
        productFamily: 'all',
        cart: [],
        discount: 0,
        customer: '',
        priceTier: 'retail',
        payment: 'cash',
        saleKey: null,
        lastSale: null,
        receiptPrinting: false,
        busy: false,
        loadedAt: null,
        settingsDirty: false,
        receiptLogoPreviewUrl: null,
        pendingLocation: null,
        refreshing: false,
        pendingSale: null,
        pendingSaleTotals: null,
        pendingSaleUncertain: false,
        restoredPendingSale: false,
        activity: null,
        activityLoading: false,
        activityError: null,
        activityPage: 1,
        activityFilters: {actor_id: '', action: '', from: '', to: ''},
        billing: null,
        billingLoading: false,
        billingError: null,
        billingCycle: 'monthly',
        billingRequest: 0,
        billingBusy: false,
        billingActionError: null,
        billingPaymentUrls: {},
        billingPaymentMessages: {},
        billingNeedsRecheck: {},
        billingStorageOwner: null,
        billingReturn: billingReturnContext(),
        billingReturnHandled: false,
        billingReturnMessage: null,
        billingHistoryOpen: false,
        heldCarts: [],
        heldOwnerKey: null,
        heldStorageAvailable: true,
        reorderRules: null,
        reorderLoading: false,
        reorderError: null,
        pendingRecordAction: null,
        recordActionRestored: false,
        cashSessions: null,
        cashLoading: false,
        cashError: null,
        commerce: null,
        commerceLoading: false,
        commerceError: null,
        commerceChannel: null,
        commerceTab: 'catalog',
        commerceSearch: '',
        commerceOrderStatus: 'all',
        analytics: null,
        analyticsLoading: false,
        analyticsError: null,
        analyticsReceiptError: null,
        analyticsFilters: null,
        analyticsDraft: null,
        analyticsRequest: 0,
        receiptHistory: null,
        receiptHistoryLoading: false,
        receiptHistoryError: null,
        receiptHistoryRequest: 0,
        receiptHistoryPage: 1,
        receiptHistorySearch: '',
        receiptHistoryDraft: '',
        receiptHistoryContext: null,
        saleRecordRequest: 0,
        reportView: new URLSearchParams(location.search).get('view') === 'financial' ? 'financial' : 'analytics',
        financial: null,
        financialLoading: false,
        financialError: null,
        financialFilters: null,
        financialDraft: null,
        financialRequest: 0,
        financialOpen: [],
        monitoring: null,
        monitoringLoading: false,
        monitoringError: null,
        monitoringRequest: 0,
        monitoringBusy: false,
        monitoringActionError: null,
        monitoringProbeUncertain: false,
        monitoringCooldownUntil: 0,
    };
    let activeRefresh = null;
    const returnFormSales = new WeakMap();

    const paths = {
        channels: '<rect x="2" y="3" width="7" height="6" rx="1"/><rect x="15" y="3" width="7" height="6" rx="1"/><rect x="8.5" y="15" width="7" height="6" rx="1"/><path d="M5.5 9v3H19V9M12 12v3"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        stock: '<path d="m3 7 9-5 9 5v10l-9 5-9-5V7Zm0 0 9 5 9-5M12 12v10M7 4l9 5"/>',
        dollar: '<path d="M12 2v20M17 6H9a3 3 0 0 0 0 6h6a3 3 0 0 1 0 6H6"/>',
        receipt: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3ZM9 7h6M9 11h6M9 15h3"/>',
        cart: '<path d="M2 3h3l3 12h11l3-9H6M9 20h.01M18 20h.01"/>',
        check: '<path d="m5 12 4 4L19 6"/>',
        close: '<path d="m6 6 12 12M6 18 18 6"/>',
        transfer: '<path d="M3 7h17M16 3l4 4-4 4M21 17H4M8 13l-4 4 4 4"/>',
        people: '<circle cx="9" cy="8" r="4"/><path d="M2 21v-3a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v3M17 4a4 4 0 0 1 0 8M20 21v-3a5 5 0 0 0-3-4.6"/>',
        alert: '<path d="m12 3 10 18H2L12 3ZM12 9v4M12 17h.01"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        download: '<path d="M12 3v12M7 10l5 5 5-5M4 16v5h16v-5"/>',
        trend: '<path d="m3 17 6-6 4 4 8-10M15 5h6v6"/>',
        cash: '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M5 8h.01M19 16h.01"/>',
        card: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 9h20M6 15h4"/>',
        wallet: '<path d="M3 7h16v14H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14v4M15 12h7v5h-7v-5ZM18 14.5h.01"/>',
        split: '<path d="M12 21V9M12 9 5 3M12 9l7-6M5 3v5M5 3h5M19 3v5M19 3h-5"/>',
        guide: '<path d="M12 5c-3-2-7-2-10-1v15c3-1 7-1 10 1 3-2 7-2 10-1V4c-3-1-7-1-10 1Zm0 0v15"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
        health: '<path d="M2 12h4l3-8 6 16 3-8h4"/>',
        refresh: '<path d="M20 7V2M20 7h-5M20 7a9 9 0 1 0 1 8"/>',
        print: '<path d="M6 9V3h12v6M6 18H3V9h18v9h-3M6 14h12v7H6zM17 11h.01"/>',
        return: '<path d="M9 4 3 10l6 6M3 10h12a6 6 0 0 1 0 12"/>',
    };
    const icon = (name) => `<svg viewBox="0 0 24 24" aria-hidden="true">${paths[name] || paths.info}</svg>`;
    const escape = (value) => String(value ?? '').replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
    const number = value => Number(value) || 0;
    const money = value => new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP', minimumFractionDigits: 2}).format(number(value));
    const count = value => new Intl.NumberFormat('en-PH').format(number(value));
    const itemsLabel = value => `${count(value)} ${number(value) === 1 ? 'item' : 'items'}`;
    const date = (value, options = {}) => value ? new Intl.DateTimeFormat('en-PH', {month: 'short', day: 'numeric', ...options, timeZone: 'Asia/Manila'}).format(new Date(value)) : '—';
    const time = value => date(value, {hour: 'numeric', minute: '2-digit'});
    const initials = name => String(name || 'S').split(/\s+/).slice(0, 2).map(word => word[0]).join('').toUpperCase();
    const round = value => Math.round((number(value) + Number.EPSILON) * 100) / 100;
    const isAdmin = () => state.data?.user?.role === 'admin';
    const canManage = () => ['admin', 'manager'].includes(state.data?.user?.role);
    const products = () => state.data?.products || [];
    const locations = () => state.data?.locations || [];
    const currentLocation = () => locations().find(location => number(location.id) === state.location);
    const productById = id => products().find(product => number(product.id) === number(id));
    const productFamilies = () => {
        const families = new Map((state.data?.product_families || []).map(family => [number(family.id), family]));
        products().filter(product => product.product_family_id).forEach(product => {
            const id = number(product.product_family_id);
            if (!families.has(id)) { families.set(id, {id, name: product.family_name, option_names: product.option_names || [], variant_count: products().filter(item => number(item.product_family_id) === id).length}); }
        });
        return [...families.values()];
    };
    const productFamilyById = id => productFamilies().find(family => number(family.id) === number(id));
    const familyProducts = id => products().filter(product => number(product.product_family_id) === number(id));
    const productPrice = product => state.priceTier === 'wholesale' ? product?.wholesale_price : product?.price;
    const hasTierPrice = product => state.priceTier !== 'wholesale' || (product?.wholesale_price !== null && product?.wholesale_price !== undefined);
    const isChannelSale = sale => ['channel', 'commerce'].includes(sale.source);
    const saleNet = sale => number(sale.net_total ?? sale.total);
    const subscription = () => state.data?.subscription || state.billing?.subscription || {};
    const canWrite = () => subscription().can_write !== false;
    const stock = (product, location = state.location) => number(product?.stocks?.find(item => number(item.location_id) === number(location))?.quantity);
    const stockRecord = (product, location = state.location) => product?.stocks?.find(item => number(item.location_id) === number(location));
    const reservedStock = (product, location = state.location) => number(stockRecord(product, location)?.reserved_quantity);
    const availableStock = (product, location = state.location) => number(stockRecord(product, location)?.available_quantity ?? Math.max(0, stock(product, location) - reservedStock(product, location)));
    const locationName = id => locations().find(location => number(location.id) === number(id))?.name || 'Location';
    const completedSales = () => (state.data?.sales || []).filter(sale => number(sale.location_id) === state.location && sale.status !== 'returned');
    const allLocationSales = () => (state.data?.sales || []).filter(sale => number(sale.location_id) === state.location);
    const simulationSalesNote = () => allLocationSales().some(isChannelSale) ? '<p class="update-stamp">Includes simulated channel sales.</p>' : '';
    const lowStock = () => products().filter(product => availableStock(product) <= number(product.reorder_level));
    const alertsEnabled = () => state.data?.settings?.low_stock_alerts !== false;
    const limitedHistory = () => number(state.data?.history_total) > number(state.data?.history_limit || 500);
    const historyLabel = () => limitedHistory() ? `Latest ${count(state.data.history_limit)} receipts` : 'All recorded sales';
    const locationStats = () => state.data?.stats_by_location?.[state.location] || {};
    const statusBadge = (status, className = '') => `<span class="badge ${className}">${escape(status)}</span>`;
    const button = (label, action, className = '', iconName = '', extra = '') => `<button type="button" class="button ${className}" data-action="${action}" ${extra}>${iconName ? icon(iconName) : ''}${label}</button>`;
    const help = (text, anchor = state.section) => `<div class="inline-help">${icon('info')}<span>${text} <a href="/guide#${anchor}">Read the user guide</a></span></div>`;
    const heading = (title, subtitle, actions = '', eyebrow = 'YOUR WORKSPACE', className = '') => `<div class="page-heading${className ? ` ${escape(className)}` : ''}"><div>${eyebrow ? `<div class="eyebrow">${eyebrow}</div>` : ''}<h1>${title}</h1><p>${subtitle}</p></div><div class="heading-actions">${actions}</div></div>`;
    const empty = (title, text, action = '', compact = false, iconName = 'stock') => `<div class="empty-state ${compact ? 'compact' : ''}">${icon(iconName)}<h2>${title}</h2><p>${text}</p>${action}</div>`;
    const field = (label, name, value = '', type = 'text', attributes = '', hint = '', full = false) => `<div class="field ${full ? 'full' : ''}"><label for="field-${name}">${label}</label><input id="field-${name}" name="${name}" type="${type}" value="${escape(value)}" ${attributes}>${hint ? `<small>${hint}</small>` : ''}</div>`;
    const selectField = (label, name, options, hint = '', full = false, required = true) => `<div class="field ${full ? 'full' : ''}"><label for="field-${name}">${label}</label><select id="field-${name}" name="${name}" ${required ? 'required' : ''}>${options}</select>${hint ? `<small>${hint}</small>` : ''}</div>`;
    const locationOptions = (selected = state.location, exclude = null) => locations().filter(location => number(location.id) !== number(exclude)).map(location => `<option value="${number(location.id)}" ${number(location.id) === number(selected) ? 'selected' : ''}>${escape(location.name)}</option>`).join('');

    function productArt(product, mini = false) {
        const id = number(product.id);
        const palettes = [['#ece5d7', '#95886b'], ['#e4e9dc', '#75816b'], ['#eee0d5', '#b68563'], ['#e5e5e8', '#7e8296'], ['#eee1dc', '#a76d5b']];
        const [background, base] = palettes[id % palettes.length];
        const color = /^#[\da-f]{6}$/i.test(product.color || '') ? product.color : base;
        const name = `${product.name} ${product.category}`.toLowerCase();
        let shapes;
        if (/shirt|tee|apparel|cloth|hoodie|fashion/.test(name)) {
            shapes = `<path d="m32 24 12-5c2 6 10 6 13 0l12 5 13 17-13 10-6-9v40H37V42l-7 9-12-10 14-17Z" fill="${color}" stroke="#493c2f" stroke-opacity=".2"/><path d="M44 19c2 6 10 6 13 0M37 70h26" stroke="#fff" stroke-opacity=".4"/><path d="M43 48h15" stroke="#fff" stroke-opacity=".6"/>`;
        } else if (/coffee|bean|tea|espresso|latte|cappuccino/.test(name)) {
            shapes = /beans|tea|bag/.test(name) ? `<path d="M30 17h40l-3 19 6 46H27l6-46-3-19Z" fill="${color}"/><path d="M30 23h40M33 36h34" stroke="#fff" stroke-opacity=".35"/><rect x="35" y="44" width="30" height="25" rx="2" fill="#f0e8d7"/><path d="M47 50c-8 7-5 13 3 10 8-6 8-13-3-10Z" fill="#715844"/><path d="m44 60 10-9" stroke="#ecdebd"/>` : `<path d="M29 31h43l-7 51H36l-7-51Z" fill="${color}"/><path d="M25 26h51v8H25z" fill="#e9dfcd"/><path d="M31 21h38l3 5H29l2-5Z" fill="#f6efe0"/><path d="M31 46h39l-3 23H34l-3-23Z" fill="#f0e6d2"/><path d="M50 51c-8 3-8 11 0 13 8-2 8-10 0-13Z" fill="#8c6348"/><path d="M50 52v11" stroke="#ddc2a0"/>`;
        } else if (/drink|juice|water|soda|bottle|beverage|oil/.test(name)) {
            shapes = `<rect x="41" y="11" width="18" height="10" rx="2" fill="#565d4b"/><path d="M41 21h18v11l9 10v38a6 6 0 0 1-6 6H38a6 6 0 0 1-6-6V42l9-10V21Z" fill="${color}"/><path d="M39 24v11l-7 8M40 43v35" stroke="#fff" stroke-opacity=".35"/><rect x="32" y="48" width="36" height="25" fill="#f2ead8"/><circle cx="50" cy="60" r="8" fill="#c68d59"/><path d="M49 54c-2 4-1 7 3 9M47 63l6-5" stroke="#ead6a5"/>`;
        } else if (/bread|bakery|croissant|pastry|food|snack|cookie/.test(name)) {
            shapes = `<path d="M24 39c5-20 48-20 53 0 7 11 1 39-7 43H30c-9-5-13-32-6-43Z" fill="#bd9464"/><path d="M26 42c3-19 43-19 47 0M36 41l-4 13M50 38l-4 14M64 41l-4 13" stroke="#ead0a1" stroke-width="3"/><path d="M29 65h44" stroke="#997447" stroke-opacity=".4"/><path d="M28 75h44l-2 7H30l-2-7Z" fill="#8e7049" fill-opacity=".3"/>`;
        } else if (/mug|cup|ceramic|homeware/.test(name)) {
            shapes = `<path d="M66 36h9c14 0 14 28 0 28h-9" stroke="${color}" stroke-width="7"/><path d="M27 32h42v40c0 18-42 18-42 0V32Z" fill="${color}"/><ellipse cx="48" cy="32" rx="21" ry="7" fill="#e2d4bc"/><ellipse cx="48" cy="32" rx="16" ry="4" fill="#73614c"/><path d="M33 43v23" stroke="#fff" stroke-opacity=".2" stroke-width="3"/>`;
        } else if (/soap|wash|shampoo|body|beauty|skin/.test(name)) {
            shapes = `<path d="M43 12h14v11H43zM50 12V7h21v4H57" fill="#5f6558" stroke="#5f6558" stroke-width="3"/><rect x="30" y="23" width="40" height="61" rx="7" fill="${color}"/><rect x="30" y="44" width="40" height="27" fill="#efe8d8"/><path d="M49 49c-8 4-8 12 1 16 7-4 9-11-1-16Z" stroke="#8a946d" fill="#bac3a0"/><path d="M50 51v13" stroke="#8a946d"/>`;
        } else {
            shapes = `<path d="M28 24h44v59H28z" fill="${color}"/><path d="m28 24 8-10h29l7 10H28Z" fill="#ccbea1"/><path d="M36 14v10M65 14v10" stroke="#9b8969"/><rect x="35" y="38" width="30" height="28" rx="1" fill="#f4ecdb"/><path d="M40 48h20M43 53h14M46 58h8" stroke="#a8997b"/><path d="M33 28v48" stroke="#fff" stroke-opacity=".3"/>`;
        }
        return `<div class="${mini ? 'mini-art' : 'product-art'}" style="background:${background}"><svg viewBox="0 0 100 100" aria-hidden="true" style="stroke:none">${shapes}</svg></div>`;
    }

    async function api(path, method = 'GET', body) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        let response;
        try {
            response = await fetch(`/api${path}`, {
                method,
                signal: controller.signal,
                credentials: 'same-origin',
                headers: {'Accept': 'application/json', ...(body instanceof FormData ? {} : {'Content-Type': 'application/json'}), 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
                ...(body !== undefined ? {body: body instanceof FormData ? body : JSON.stringify(body)} : {}),
            });
        } catch (failure) {
            throw new Error(failure.name === 'AbortError' ? 'The connection timed out. Please check your connection and try again.' : 'The server could not be reached. Please check your connection and try again.');
        } finally { clearTimeout(timeout); }
        let data;
        try { data = await response.json(); } catch {
            if (response.ok) { throw new Error('The server response could not be confirmed. Please retry this same action.'); }
            data = {};
        }
        if (!response.ok) {
            if (response.status === 401 || response.status === 419) {
                const failure = new Error('Your session has expired. Sign in again to continue.');
                failure.status = response.status;
                throw failure;
            }
            const errors = data.errors ? Object.values(data.errors).flat().join(' ') : '';
            const failure = new Error(errors || data.message || `Could not complete this action (${response.status}). Please try again.`);
            failure.status = response.status;
            throw failure;
        }
        return data;
    }

    async function refresh(render = true) {
        if (state.refreshing) { await activeRefresh; return state.data; }
        const hadData = Boolean(state.data);
        state.refreshing = true;
        let data;
        activeRefresh = api('/bootstrap');
        try { data = await activeRefresh; } finally { state.refreshing = false; activeRefresh = null; }
        state.data = data;
        state.analyticsReceiptError = null;
        if (!state.restoredPendingSale) {
            state.restoredPendingSale = true;
            try {
                const pending = JSON.parse(sessionStorage.getItem('stockroom-pending-sale') || 'null');
                if (pending && pending.tenantId === data.tenant.id && pending.userId === data.user.id && Array.isArray(pending.payload?.items) && Array.isArray(pending.payload?.payments) && pending.payload?.idempotency_key) {
                    state.pendingSale = pending.payload;
                    state.pendingSaleTotals = pending.totals;
                    state.pendingSaleUncertain = true;
                    state.cart = pending.payload.items;
                    state.location = number(pending.payload.location_id);
                    state.discount = number(pending.payload.discount_percent);
                    state.customer = pending.payload.customer_id || '';
                    state.priceTier = pending.payload.price_tier || 'retail';
                    state.saleKey = pending.payload.idempotency_key;
                    toast('A checkout needs confirmation. Open Point of sale and check its status before starting another sale.', true);
                }
            } catch { /* Storage is optional; checkout still uses the same key while this page is open. */ }
        }
        loadHeldCarts();
        restoreRecordAction();
        state.loadedAt = new Date();
        if (!locations().some(location => number(location.id) === state.location)) {
            state.location = number(locations()[0]?.id);
        }
        locationSelect.innerHTML = locationOptions();
        document.getElementById('business-name').textContent = data.tenant?.name || 'Your business';
        document.getElementById('business-initial').textContent = initials(data.tenant?.name).slice(0, 1);
        document.getElementById('profile-avatar').textContent = initials(data.user?.name);
        document.getElementById('profile-avatar').title = `${data.user?.name || ''} · ${data.user?.role || ''}`;
        document.getElementById('sidebar-user').textContent = data.user?.name || 'Your account';
        const isSample = Boolean(data.tenant?.is_demo || data.is_demo || /demo|sample|common ground/i.test(data.tenant?.name || ''));
        state.isSample = isSample;
        document.getElementById('workspace-kind').textContent = isSample ? 'Sample business · live demo' : 'Cloud inventory & POS';
        document.getElementById('connection-label').textContent = 'Connected · workspace up to date';
        document.querySelector('.workspace-status').classList.remove('offline');
        const stockBadge = document.getElementById('stock-badge');
        stockBadge.textContent = lowStock().length;
        stockBadge.hidden = !lowStock().length || !alertsEnabled();
        document.querySelector('#main-nav a[data-section="purchases"]').hidden = !canManage();
        document.querySelector('#main-nav a[data-section="channels"]').hidden = !canManage();
        document.querySelector('#main-nav a[data-section="team"]').hidden = !isAdmin();
        document.querySelector('#main-nav a[data-section="activity"]').hidden = !isAdmin();
        document.querySelector('#main-nav a[data-section="monitoring"]').hidden = !isAdmin();
        document.querySelector('#main-nav a[data-section="billing"]').hidden = !isAdmin();
        document.querySelector('#main-nav a[data-section="settings"]').hidden = !isAdmin();
        document.querySelector('.manage-caption').hidden = !isAdmin();
        if ((!isAdmin() && ['team', 'activity', 'monitoring', 'billing', 'settings'].includes(state.section)) || (!canManage() && ['purchases', 'channels'].includes(state.section))) {
            state.section = 'overview';
            history.replaceState({}, '', '/app/overview');
        }
        if (render && !(state.section === 'settings' && state.settingsDirty) && (!hadData || !document.activeElement?.matches('input,select,textarea'))) { renderSection(); }
        return data;
    }

    function sampleBanner() {
        return state.isSample ? `<div class="sample-banner">${icon('info')}<span><strong>You're in the sample workspace.</strong> Try a sale or a stock update. This business uses sample products and recorded payments.</span><a href="/guide#demo">How the demo works</a></div>` : '';
    }

    function metric(label, value, note, iconName, alert = false) {
        return `<article class="stat-card"><div class="stat-label"><span>${label}</span>${icon(iconName)}</div><div class="stat-value">${value}</div><div class="stat-note ${alert ? 'alert' : ''}"><i></i>${note}</div></article>`;
    }

    function renderSection(preserveFocus = false) {
        if (!state.data) { return; }
        if (!canManage() && state.reportView === 'financial') {
            state.reportView = 'analytics';
            if (state.section === 'reports') { history.replaceState({}, '', '/app/reports'); }
        }
        const active = preserveFocus && document.activeElement;
        const activeId = active?.id;
        const selectionStart = active?.selectionStart;
        const selectionEnd = active?.selectionEnd;
        document.getElementById('breadcrumb-section').textContent = sections[state.section][0];
        document.getElementById('context-help').href = `/guide#${state.section === 'reports' && state.reportView === 'financial' ? 'financial' : state.section}`;
        document.title = `${sections[state.section][0]} — POSONLY`;
        document.querySelectorAll('#main-nav a[data-section]').forEach(link => {
            const selected = link.dataset.section === state.section;
            link.classList.toggle('active', selected);
            if (selected) { link.setAttribute('aria-current', 'page'); } else { link.removeAttribute('aria-current'); }
        });
        const renders = {overview: renderOverview, pos: renderPos, inventory: renderInventory, purchases: renderPurchases, channels: renderChannels, customers: renderCustomers, reports: renderReports, team: renderTeam, activity: renderActivity, monitoring: renderMonitoring, billing: renderBilling, settings: renderSettings};
        clearReceiptLogoPreview();
        state.settingsDirty = false;
        view.innerHTML = renders[state.section]();
        if (state.section === 'inventory' && canManage()) {
            view.querySelector('.heading-actions').insertAdjacentHTML('afterbegin', button('Locations', 'locations', 'subtle', 'stock'));
        }
        if (state.section === 'overview' && !alertsEnabled()) {
            view.querySelector('.dashboard-grid .panel:nth-child(2)').innerHTML = `<div class="panel-header"><div><h2>Low-stock alerts are paused.</h2><p>Stock levels are still tracked in your catalog.</p></div></div><div class="quiet-state">${icon('info')}<h3>Stock tracking remains active</h3><p>${isAdmin() ? 'Turn alerts on again in Settings.' : 'Ask an administrator to turn alerts on again.'}</p>${isAdmin() ? button('Manage alerts', 'navigate', 'small subtle', '', 'data-target="settings"') : button('View inventory', 'navigate', 'small subtle', '', 'data-target="inventory"')}</div>`;
        }
        if (state.section === 'settings') {
            view.querySelector('.workspace-plan-card').innerHTML = `<h3>Billing & plans</h3><p>View your trial, choose a plan, and keep invoice records in one place.</p><div style="margin-top:20px">${button('Open billing & plans', 'navigate', 'primary', '', 'data-target="billing"')}</div><p class="plan-disclaimer">Review the payment mode and confirmation status in Billing & plans.</p>`;
        }
        if (!canWrite() && state.section !== 'billing') {
            view.querySelector('.page-heading').insertAdjacentHTML('afterend', `<div class="sample-banner pending-sale-banner">${icon('alert')}<span><strong>Sales and stock changes are paused.</strong> Your records, returns and exports remain available. ${isAdmin() ? 'Open Billing & plans to renew this local workspace.' : 'Ask an administrator to review the workspace plan.'}</span>${isAdmin() ? button('Review billing', 'navigate', 'small', '', 'data-target="billing"') : ''}</div>`);
        }
        if (state.section === 'pos' && state.cart.length) {
            view.querySelector('.pos-catalog').insertAdjacentHTML('beforeend', `<div class="mobile-cart-bar"><div><strong>${itemsLabel(state.cart.reduce((total, item) => total + item.quantity, 0))} · ${money(cartTotals().total)}</strong><span>Your current sale</span></div><button class="button primary" data-action="view-cart">View cart</button></div>`);
        }
        if (state.section === 'pos' && state.pendingSale) {
            view.querySelector('.page-heading').insertAdjacentHTML('afterend', `<div class="sample-banner pending-sale-banner">${icon('alert')}<span><strong>A checkout needs confirmation.</strong> Its cart and payment are kept safe. Retry the original request to check whether it was saved.</span><button class="text-button" data-action="charge">Check sale</button></div>`);
            view.querySelectorAll('#cart-discount, #cart-customer').forEach(input => { input.disabled = true; });
        }
        if (state.pendingRecordAction) { view.querySelector('.page-heading').insertAdjacentHTML('afterend', `<div class="sample-banner pending-sale-banner">${icon('alert')}<span><strong>A workspace update needs confirmation.</strong> Its original request is protected.</span>${button('Check update status', 'record-recovery', 'small')}</div>`); }
        if (limitedHistory() && ['overview', 'customers'].includes(state.section)) {
            view.querySelector('.page-heading').insertAdjacentHTML('afterend', `<div class="sample-banner">${icon('info')}<span>Charts, rankings and customer cards cover the latest ${count(state.data.history_limit)} permitted receipts. Business sales totals and transaction summaries include the full history.${isAdmin() ? ' Export sales for the full history.' : ''}</span></div>`);
        }
        if (activeId) {
            const replacement = document.getElementById(activeId);
            replacement?.focus({preventScroll: true});
            if (replacement && typeof selectionStart === 'number' && ['text', 'search', 'email', 'tel'].includes(replacement.type)) {
                replacement.setSelectionRange(selectionStart, selectionEnd);
            }
        }
        if (state.section === 'activity' && !state.activity && !state.activityLoading && !state.activityError) { loadActivity(); }
        if (state.section === 'billing' && !state.billing && !state.billingLoading && !state.billingError) { loadBilling(); }
        if (state.section === 'channels' && !state.commerce && !state.commerceLoading && !state.commerceError) { loadCommerce(); }
        if (state.section === 'monitoring' && isAdmin() && !state.monitoring && !state.monitoringLoading && !state.monitoringError) { loadMonitoring(); }
        if (state.section === 'reports') {
            if (state.reportView === 'financial' && canManage() && !state.financial && !state.financialLoading && !state.financialError) { loadFinancial(); }
            if (state.reportView === 'analytics' && !state.analytics && !state.analyticsLoading && !state.analyticsError) { loadAnalytics(); }
        }
    }

    function salesChart() {
        const sales = completedSales();
        const end = new Date();
        const days = Array.from({length: 7}, (_, index) => {
            const day = new Date(end);
            day.setDate(day.getDate() - (6 - index));
            const key = new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Manila'}).format(day);
            return {day, total: sales.filter(sale => new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Manila'}).format(new Date(sale.created_at)) === key).reduce((total, sale) => total + saleNet(sale), 0)};
        });
        const max = Math.max(...days.map(day => day.total), 1);
        const lines = Array.from({length: 4}, (_, index) => `<line class="chart-grid" x1="38" x2="540" y1="${15 + index * 41}" y2="${15 + index * 41}"/><text class="chart-axis" x="0" y="${18 + index * 41}">${Math.round(max * (1 - index / 3)) >= 1000 ? `${round(max * (1 - index / 3) / 1000)}k` : Math.round(max * (1 - index / 3))}</text>`).join('');
        const bars = days.map((day, index) => {
            const height = Math.max(2, day.total / max * 123);
            return `<rect class="chart-bar ${index !== 6 ? 'secondary' : ''}" x="${57 + index * 69}" y="${138 - height}" width="28" height="${height}" rx="3"><title>${date(day.day)}: ${money(day.total)}</title></rect>`;
        }).join('');
        return `<div class="sales-chart"><div class="chart-summary"><strong>${money(days.reduce((total, day) => total + day.total, 0))}</strong><span>across the last 7 days</span></div><svg viewBox="0 0 550 160" preserveAspectRatio="none" class="chart-svg" role="img" aria-label="Sales revenue for the last seven days">${lines}${bars}</svg><div class="chart-ticks">${days.map(day => `<span>${date(day.day, {weekday: 'short', month: undefined, day: undefined})}</span>`).join('')}</div><div class="chart-key"><i></i>Completed sales · ${escape(currentLocation()?.name || '')}${limitedHistory() ? ` · from latest ${count(state.data.history_limit)} receipts` : ''}</div></div>`;
    }

    function recentSalesTable(sales, full = false) {
        if (!sales.length) { return empty('No sales recorded', 'Completed checkouts will appear here, with a receipt and an exact stock record.', button('Open point of sale', 'navigate', 'primary small', '', 'data-target="pos"'), true, 'receipt'); }
        return `<div class="table-wrap"><table><thead><tr><th>Receipt</th><th>Customer</th>${full ? '<th>Date & cashier</th>' : ''}<th>Payment</th><th>Total</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>${sales.map(sale => {
            const customer = (state.data.customers || []).find(item => number(item.id) === number(sale.customer_id));
            const methods = (sale.payments || []).map(item => ({cash: 'Cash', card: 'Card', wallet: 'E-wallet'}[item.method] || item.method)).join(' + ');
            return `<tr><td><div class="table-name"><div><strong>${escape(sale.number || `#${sale.id}`)}</strong><small>${full ? `${itemsLabel((sale.items || []).reduce((total, item) => total + number(item.quantity), 0))}` : time(sale.created_at)}</small></div></div></td><td>${escape(customer?.name || 'Walk-in customer')}</td>${full ? `<td><div class="table-name"><div><strong>${time(sale.created_at)}</strong><small>${escape(sale.cashier_name || 'Team member')}</small></div></div></td>` : ''}<td>${isChannelSale(sale) ? `${statusBadge('Channel', 'green')}<small class="table-secondary">${escape(sale.channel_name || 'Commerce')} · ${escape(sale.external_reference || '')}</small>` : escape(methods || '—')}</td><td><strong>${money(saleNet(sale))}</strong>${number(sale.returned_amount) ? `<small class="table-secondary">${money(sale.returned_amount)} returned</small>` : ''}</td><td>${statusBadge(sale.status === 'returned' ? 'Returned' : sale.status === 'partially_returned' ? 'Partly returned' : 'Completed', sale.status === 'completed' ? 'green' : 'amber')}</td><td><div class="table-actions"><button data-action="receipt" data-id="${number(sale.id)}" aria-label="View receipt ${escape(sale.number)}">Receipt</button>${full && canManage() && sale.status !== 'returned' ? `<button class="danger" data-action="return" data-id="${number(sale.id)}">Return</button>` : ''}</div></td></tr>`;
        }).join('')}</tbody></table></div>`;
    }

    function renderOverview() {
        const sales = completedSales();
        const revenue = number(locationStats().revenue_total ?? sales.reduce((total, sale) => total + saleNet(sale), 0));
        const transactions = number(locationStats().transactions ?? sales.length);
        const units = products().reduce((total, product) => total + stock(product), 0);
        const low = lowStock();
        return `${heading('Overview', sections.overview[1], button('Open checkout', 'navigate', 'primary', 'plus', 'data-target="pos"'), '', 'workspace-heading')}${sampleBanner()}${simulationSalesNote()}<div class="stats-grid">${metric('Recorded sales total', money(revenue), 'After discounts, including tax', 'dollar')}${metric('Completed sales', count(transactions), `${transactions ? money(revenue / transactions) : money(0)} average sale`, 'receipt')}${metric('Units in stock', count(units), `${products().length} products in your catalog`, 'stock')}${metric('Low-stock products', count(low.length), low.length ? 'At or below the reorder level' : 'No low-stock products', 'alert', low.length > 0)}</div><div class="dashboard-grid"><section class="panel"><div class="panel-header"><div><h2>Sales trend</h2><p>Recorded daily sales totals.</p></div><span class="period-label">Last 7 days</span></div>${salesChart()}</section><section class="panel"><div class="panel-header"><div><h2>Low-stock products</h2><p>Products at or below their reorder level.</p></div><button class="text-button" data-action="navigate" data-target="inventory">View all</button></div>${low.length ? `<div class="low-stock-list">${low.slice(0, 4).map(product => `<div class="low-stock-row">${productArt(product, true)}<div><strong>${escape(product.name)}</strong><small>${escape(product.sku)}</small></div>${statusBadge(stock(product) === 0 ? 'Out of stock' : `${availableStock(product)} available`, stock(product) === 0 ? 'red' : 'amber')}</div>`).join('')}</div>` : `<div class="quiet-state">${icon('check')}<h3>No low-stock products</h3><p>Products appear here when available stock reaches the reorder level.</p></div>`}</section></div><section class="panel recent-sales"><div class="panel-header"><div><h2>Recent sales</h2><p>Latest recorded sales at this location.</p></div><button class="text-button" data-action="navigate" data-target="reports">See reports</button></div>${recentSalesTable(allLocationSales().slice(0, 5))}</section><div class="quick-actions"><button class="quick-action" data-action="navigate" data-target="pos">${icon('receipt')}<div><strong>Start a new sale</strong><small>Add items and record payment.</small></div></button><button class="quick-action" data-action="${canManage() ? 'add-product' : 'navigate'}" ${canManage() ? '' : 'data-target="inventory"'}>${icon('stock')}<div><strong>${canManage() ? 'Add a product' : 'View inventory'}</strong><small>Products, prices and stock.</small></div></button><a class="quick-action" href="/guide">${icon('guide')}<div><strong>User guide</strong><small>Instructions for your team.</small></div></a></div>`;
    }

    function filteredProducts() {
        const search = state.search.trim().toLowerCase();
        return products().filter(product => (state.category === 'All products' || product.category === state.category) && (state.section !== 'inventory' || state.productFamily === 'all' || (state.productFamily === 'standalone' ? !product.product_family_id : number(product.product_family_id) === number(state.productFamily))) && (!search || `${product.name} ${product.family_name || ''} ${product.sku} ${product.barcode || ''} ${product.variant || ''} ${Object.values(product.variant_options || {}).join(' ')}`.toLowerCase().includes(search)));
    }

    function searchToolbar(placeholder = 'Search products, SKU or barcode…', filters = true) {
        const categories = [...new Set(products().map(product => product.category).filter(Boolean))];
        return `<div class="toolbar"><label class="search-field">${icon('search')}<span class="sr-only">Search</span><input id="workspace-search" type="search" value="${escape(state.search)}" placeholder="${placeholder}" autocomplete="off"></label>${filters ? `<select id="category-select" class="filter-select" aria-label="Filter category"><option>All products</option>${categories.map(category => `<option ${state.category === category ? 'selected' : ''}>${escape(category)}</option>`).join('')}</select>` : ''}${filters && state.section === 'inventory' && productFamilies().length ? `<select id="family-select" class="filter-select" aria-label="Filter product family"><option value="all">All product families</option><option value="standalone" ${state.productFamily === 'standalone' ? 'selected' : ''}>Standalone products</option>${productFamilies().map(family => `<option value="${number(family.id)}" ${number(state.productFamily) === number(family.id) ? 'selected' : ''}>${escape(family.name)}</option>`).join('')}</select>` : ''}<span class="results-count">${filters ? `${filteredProducts().length} products` : ''}</span></div>`;
    }

    const uuid = () => window.crypto?.randomUUID ? crypto.randomUUID() : `record-${Date.now()}-${Math.random().toString(36).slice(2)}`;

    function loadHeldCarts() {
        const key = `stockroom-held:${state.data.tenant.id}:${state.data.user.id}`;
        if (state.heldOwnerKey === key) { return; }
        state.heldOwnerKey = key;
        try {
            const record = JSON.parse(localStorage.getItem(key) || '{"carts":[]}');
            state.heldCarts = (Array.isArray(record.carts) ? record.carts : []).filter(cart => typeof cart.id === 'string' && typeof cart.name === 'string' && Number.isFinite(new Date(cart.saved_at).getTime()) && Array.isArray(cart.items)).map(cart => ({...cart, items: cart.items.filter(item => Number.isInteger(item.product_id) && Number.isInteger(item.quantity) && item.quantity > 0)})).filter(cart => cart.items.length);
        } catch { state.heldCarts = []; state.heldStorageAvailable = false; }
    }

    function persistHeldCarts(carts) {
        try { localStorage.setItem(state.heldOwnerKey, JSON.stringify({version: 1, carts})); state.heldCarts = carts; return true; }
        catch { toast('This browser cannot save carts. Your current cart is still here. Enable browser storage and try again.', true); return false; }
    }

    function heldCartsModal() {
        openModal('Held carts', 'Saved in this browser for your account. Stock is not reserved.', `${state.heldCarts.length ? `<div class="held-list">${state.heldCarts.map(cart => `<article class="held-card"><div><strong>${escape(cart.name)}</strong><small>${itemsLabel(cart.items.reduce((sum, item) => sum + item.quantity, 0))} · ${escape(locationName(cart.location_id))} · ${cart.price_tier === 'wholesale' ? 'Wholesale' : 'Retail'}<br>${time(cart.saved_at)}</small></div><div>${button('Resume', 'resume-held', 'small primary', '', `data-id="${escape(cart.id)}" ${state.pendingSale ? 'disabled' : ''}`)}${button('Delete', 'delete-held', 'small subtle', '', `data-id="${escape(cart.id)}" ${state.pendingSale ? 'disabled' : ''}`)}</div></article>`).join('')}</div>` : empty('No carts on hold.', 'Save the current sale when your customer needs a little more time.', '', true, 'clock')}<div class="modal-tip">Saved carts use current catalog prices and available stock when resumed. They stay in this browser; checkout needs a server connection.</div><div class="form-actions">${button('Back to sale', 'close-modal', 'primary')}</div>`);
    }

    function holdCartModal() {
        if (!state.cart.length || state.pendingSale) { return; }
        openModal('Save cart', 'Saving a cart does not deduct or reserve stock.', modalForm('hold-cart', field('Cart name', 'name', `Counter · ${date(new Date(), {hour: 'numeric', minute: '2-digit'})}`, 'text', 'required maxlength="80"', 'For example: Mia · back in ten minutes.', true), 'Save cart'));
    }

    async function resumeHeldCart(id, confirmed = false) {
        const cart = state.heldCarts.find(cart => cart.id === id);
        if (!cart || state.pendingSale) { return; }
        if (state.cart.length && !confirmed) {
            openModal('Replace current cart?', escape(cart.name), `<p class="danger-copy">This replaces your current unsaved cart. Save that cart first if you want to keep it.</p><div class="form-actions">${button('Keep current cart', 'close-modal')}${button('Replace & resume', 'confirm-resume-held', 'primary', '', `data-id="${escape(id)}"`)}</div>`); return;
        }
        try {
            await refresh(false);
            if (!locations().some(location => number(location.id) === number(cart.location_id))) { throw new Error('The saved location is no longer available. This cart has been kept on hold.'); }
            if (cart.items.some(item => !productById(item.product_id))) { throw new Error('A product in this cart is no longer available. This cart has been kept on hold.'); }
            const tier = cart.price_tier === 'wholesale' ? 'wholesale' : 'retail';
            if (tier === 'wholesale' && cart.items.some(item => productById(item.product_id).wholesale_price == null)) { throw new Error('A product in this saved cart has no wholesale price. Set its wholesale price in Inventory, then resume again.'); }
            if (!persistHeldCarts(state.heldCarts.filter(item => item.id !== id))) { return; }
            state.cart = cart.items.map(item => ({...item})); state.location = number(cart.location_id); state.priceTier = tier; state.discount = Math.min(100, Math.max(0, number(cart.discount_percent))); state.customer = state.data.customers.some(customer => number(customer.id) === number(cart.customer_id)) ? String(cart.customer_id) : ''; state.saleKey = null;
            locationSelect.value = state.location; modal.close(); navigate('pos');
            const stockChanged = state.cart.some(item => item.quantity > availableStock(productById(item.product_id)));
            toast(stockChanged ? 'Cart resumed. Some quantities exceed current stock; review them before checkout.' : 'Cart resumed with current prices and stock.', stockChanged);
        } catch (failure) { toast(failure.message, true); }
    }

    function billingReturnContext() {
        const query = new URLSearchParams(location.search);
        const outcome = query.get('paymongo_return');
        if (!['success', 'cancel'].includes(outcome) || location.pathname !== '/app/billing') { return null; }
        const id = Number(query.get('invoice'));
        return {outcome, invoice_id: Number.isSafeInteger(id) && id > 0 ? id : null};
    }

    function billingConfiguration() {
        return state.billing?.payment_config || {provider: 'simulation', mode: 'simulation', configured: true, checkout_enabled: false, notice: state.billing?.notice};
    }

    const paymongoModeLabel = mode => mode === 'live' ? 'Live mode' : mode === 'test' ? 'Test mode' : 'Mode not recorded';
    const paymongoSourceLabel = mode => mode === 'live' ? 'PayMongo · live' : mode === 'test' ? 'PayMongo · test' : 'PayMongo · mode not recorded';
    const paymongoCheckoutLabel = mode => mode === 'test' ? 'PayMongo test checkout' : 'PayMongo checkout';
    const paymongoDashboardLabel = mode => mode === 'live' ? 'PayMongo live dashboard' : mode === 'test' ? 'PayMongo test dashboard' : 'PayMongo dashboard';
    const paymongoMethodLabels = methods => (Array.isArray(methods) ? methods : []).map(method => ({card: 'Card', gcash: 'GCash', qrph: 'QR Ph'}[method] || String(method))).join(', ');
    const billingAttemptModeMatches = (attempt, config = billingConfiguration()) => !attempt || attempt.mode === config.mode;
    const billingModeNotice = config => config.mode === 'live' ? 'Live checkout collects real payments for individual invoices. Payment is confirmed on the server; automatic card renewal is not configured.' : 'Hosted checkouts use test mode. No live payment is collected and no automatic card renewal is configured.';

    function restoreBillingRechecks() {
        const owner = `stockroom:billing-recheck:${number(state.data?.tenant?.id)}:${number(state.data?.user?.id)}`;
        if (owner === state.billingStorageOwner) { return; }
        state.billingStorageOwner = owner;
        state.billingNeedsRecheck = {}; state.billingPaymentUrls = {}; state.billingPaymentMessages = {};
        try {
            const stored = JSON.parse(sessionStorage.getItem(owner) || '[]');
            if (Array.isArray(stored)) { stored.filter(id => Number.isSafeInteger(id) && id > 0).slice(0, 100).forEach(id => { state.billingNeedsRecheck[id] = true; }); }
        } catch { /* Recovery also works in memory when browser storage is unavailable. */ }
    }

    function markBillingRecheck(id, required) {
        if (required) { state.billingNeedsRecheck[id] = true; } else { delete state.billingNeedsRecheck[id]; }
        try { sessionStorage.setItem(state.billingStorageOwner, JSON.stringify(Object.keys(state.billingNeedsRecheck).map(Number))); } catch { /* Keep the current invoice protected in memory. */ }
    }

    function applyBillingState(result) {
        const firstLoad = !state.billing;
        state.billing = result; state.billingError = null;
        if (firstLoad) { state.billingCycle = result.subscription.billing_cycle || 'monthly'; }
        if (state.data) {
            state.data.subscription = {...state.data.subscription, ...result.subscription};
            state.data.settings = {...state.data.settings, plan: result.subscription.plan, billing_cycle: result.subscription.billing_cycle};
        }
        (result.invoices || []).filter(invoice => invoice.status !== 'open').forEach(invoice => {
            markBillingRecheck(invoice.id, false); delete state.billingPaymentUrls[invoice.id];
        });
    }

    async function loadBilling(silent = false) {
        if (!isAdmin() || state.billingBusy) { return; }
        restoreBillingRechecks();
        const request = ++state.billingRequest;
        state.billingLoading = true; state.billingError = null;
        if (!silent && state.section === 'billing') { renderSection(true); }
        try {
            const result = await api('/billing');
            if (request !== state.billingRequest) { return; }
            applyBillingState(result);
        } catch (failure) {
            if (request !== state.billingRequest) { return; }
            state.billingError = failure.message;
        } finally {
            if (request === state.billingRequest) { state.billingLoading = false; if (state.section === 'billing') { renderSection(true); } }
        }
        if (request === state.billingRequest && state.billing && state.billingReturn && !state.billingReturnHandled) {
            state.billingReturnHandled = true;
            const returned = state.billingReturn;
            state.billingReturnMessage = returned.outcome === 'success' ? 'Checkout returned. The server will check whether payment was confirmed.' : 'Checkout closed. The invoice stays in your records while the server checks its payment status.';
            if (state.section === 'billing') { renderSection(true); }
            if (returned.invoice_id && billingConfiguration().provider === 'paymongo' && billingConfiguration().configured) { await paymongoInvoiceAction(returned.invoice_id, 'recheck'); }
            else if (returned.invoice_id) { state.billingReturnMessage = `Payment could not be rechecked. ${billingConfiguration().reason || 'Complete private payment configuration, then open the invoice and recheck.'}`; if (state.section === 'billing') { renderSection(true); } }
            else { state.billingActionError = 'The returned invoice could not be identified. Open your invoice and choose Recheck payment.'; if (state.section === 'billing') { renderSection(true); } }
        }
    }

    const billingAttemptLabels = {creating: 'Creating checkout', pending: 'Waiting for payment', unknown: 'Confirmation needed', failed: 'Failed', expired: 'Expired', paid: 'Payment confirmed', needs_review: 'Needs review'};
    const billingAttemptBadge = attempt => statusBadge(billingAttemptLabels[attempt?.status] || 'Unknown', attempt?.status === 'paid' ? 'green' : attempt?.status === 'failed' ? 'red' : ['creating', 'pending', 'unknown', 'needs_review'].includes(attempt?.status) ? 'amber' : '');
    function billingAttempts(invoice) {
        const rows = [...(invoice?.attempts || []), ...(state.billing?.payment_attempts || []).filter(attempt => number(attempt.invoice_id) === number(invoice?.id)), ...(invoice?.confirmed_attempt ? [invoice.confirmed_attempt] : []), ...(invoice?.latest_attempt ? [invoice.latest_attempt] : [])];
        return [...new Map(rows.map(attempt => [number(attempt.id), attempt])).values()].sort((a, b) => number(b.id) - number(a.id));
    }
    const latestBillingAttempt = invoice => billingAttempts(invoice)[0];
    const billingAttemptForInvoice = id => latestBillingAttempt(state.billing?.invoices.find(invoice => number(invoice.id) === number(id)) || {id});
    const billingNeedsSessionRecovery = attempt => Boolean(attempt && !attempt.session_id && ['creating', 'unknown'].includes(attempt.status));
    const simulatedInvoice = invoice => invoice.payment_source === 'simulation' || (invoice.payment_provider || invoice.provider) === 'simulation' || (invoice.payment_mode || invoice.mode) === 'simulation' || invoice.payment_method === 'simulation';
    const paymongoInvoice = invoice => (invoice.payment_provider || invoice.provider) === 'paymongo' || ['paymongo', 'paymongo_test', 'paymongo_live', 'paymongo_unknown'].includes(invoice.payment_source) || ['test', 'live'].includes(invoice.payment_mode || invoice.mode) || Boolean(latestBillingAttempt(invoice));
    const invoicePaymentMode = invoice => invoice.payment_mode || invoice.mode || invoice.confirmed_attempt?.mode || (invoice.payment_source === 'paymongo_live' ? 'live' : invoice.payment_source === 'paymongo_test' ? 'test' : null) || latestBillingAttempt(invoice)?.mode;
    const invoiceSourceLabel = invoice => simulatedInvoice(invoice) ? 'Local simulation' : paymongoInvoice(invoice) ? paymongoSourceLabel(invoicePaymentMode(invoice)) : invoice.status === 'paid' ? 'Recorded payment' : 'No payment recorded';
    const invoiceSourceColor = invoice => simulatedInvoice(invoice) || (paymongoInvoice(invoice) && invoicePaymentMode(invoice) !== 'live') ? 'amber' : paymongoInvoice(invoice) ? 'green' : '';
    const invoiceStatusBadge = invoice => statusBadge(invoice.status === 'paid' ? simulatedInvoice(invoice) ? 'Simulated · paid' : paymongoInvoice(invoice) ? invoicePaymentMode(invoice) === 'live' ? 'Payment confirmed' : invoicePaymentMode(invoice) === 'test' ? 'Test payment confirmed' : 'Provider payment recorded' : 'Paid record' : invoice.status === 'void' ? 'Void' : 'Open', invoice.status === 'paid' ? 'green' : invoice.status === 'open' ? 'amber' : '');

    function safeBillingCheckoutUrl(value) {
        if (typeof value !== 'string') { return null; }
        try {
            const url = new URL(value);
            return url.origin === 'https://checkout.paymongo.com' && !url.username && !url.password ? url.href : null;
        } catch { return null; }
    }

    function billingAttemptMarkup(attempt) {
        return `<article class="billing-attempt"><div class="billing-attempt-heading"><div><strong>${escape(paymongoSourceLabel(attempt.mode))} · attempt #${number(attempt.id)}</strong><small>Started ${time(attempt.created_at)}</small></div>${billingAttemptBadge(attempt)}</div><div class="billing-attempt-meta"><span>${money(attempt.amount)} · ${escape(attempt.currency || 'PHP')}</span>${attempt.reference ? `<span>Reference: ${escape(attempt.reference)}</span>` : ''}${attempt.session_id ? `<span>Checkout: ${escape(attempt.session_id)}</span>` : ''}${attempt.payment_id ? `<span>Payment: ${escape(attempt.payment_id)}</span>` : ''}${attempt.confirmed_at ? `<span>Confirmed ${time(attempt.confirmed_at)}</span>` : ''}</div>${attempt.failure_summary ? `<p>${escape(attempt.failure_summary)}</p>` : ''}</article>`;
    }

    function billingAttemptModal(attempt) {
        if (!attempt) { return; }
        const invoice = state.billing?.invoices.find(record => number(record.id) === number(attempt.invoice_id));
        if (invoice) { invoiceModal(invoice); return; }
        const config = billingConfiguration();
        const enabled = config.provider === 'paymongo' && config.configured && billingAttemptModeMatches(attempt, config) && !state.billingBusy && !state.billingLoading;
        const note = attempt.mode === 'live' ? 'This server-recorded attempt is a live payment checkout. Payment confirmation comes from server verification, not a browser return.' : attempt.mode === 'test' ? 'This server-recorded attempt is a test checkout. No live payment is collected.' : 'This server-recorded attempt has no recorded payment mode. Do not infer a live payment from this record.';
        const modeNotice = !billingAttemptModeMatches(attempt, config) ? `<p class="modal-tip">This record uses ${escape(paymongoModeLabel(attempt.mode).toLowerCase())}. The current provider configuration cannot verify an attempt from a different mode.</p>` : '';
        openModal(`Checkout attempt #${number(attempt.id)}`, `Invoice #${number(attempt.invoice_id)} · ${escape(paymongoSourceLabel(attempt.mode))}`, `<div class="billing-invoice" data-billing-invoice="${number(attempt.invoice_id)}">${billingAttemptMarkup(attempt)}<div class="modal-tip">This invoice is outside the recent invoice list. ${escape(note)} Invoice payments do not set up automatic card renewal.</div>${modeNotice}${state.billingPaymentMessages[attempt.invoice_id] ? `<p class="danger-copy" role="status">${escape(state.billingPaymentMessages[attempt.invoice_id])}</p>` : ''}<div class="form-actions">${button('Close', 'close-modal')}${config.provider === 'paymongo' && billingNeedsSessionRecovery(attempt) ? button('Recover checkout', 'billing-payment-recover', 'primary', '', `data-id="${number(attempt.invoice_id)}" ${!enabled ? 'disabled' : ''}`) : ''}${config.provider === 'paymongo' ? button('Recheck payment', 'billing-payment-recheck', 'subtle', 'refresh', `data-id="${number(attempt.invoice_id)}" ${!enabled ? 'disabled' : ''}`) : ''}</div></div>`);
    }

    function refreshBillingDialog(id) {
        if (!modal.open || !modalContent.querySelector(`[data-billing-invoice="${number(id)}"]`)) { return; }
        const invoice = state.billing?.invoices.find(record => number(record.id) === number(id));
        if (invoice) { invoiceModal(invoice); }
        else { billingAttemptModal(state.billing?.payment_attempts?.find(attempt => number(attempt.invoice_id) === number(id))); }
    }

    function billingRecoveryModal(id) {
        const config = billingConfiguration();
        const attempt = billingAttemptForInvoice(id);
        if (!isAdmin() || state.billingBusy || state.billingLoading || config.provider !== 'paymongo' || !config.configured || !billingNeedsSessionRecovery(attempt) || !billingAttemptModeMatches(attempt, config)) { return; }
        const invoice = state.billing?.invoices.find(record => number(record.id) === number(id));
        openModal('Recover checkout', `${escape(invoice?.number || `Invoice #${number(id)}`)} · ${escape(paymongoSourceLabel(attempt.mode))}`, `<div data-billing-recovery="${number(id)}">${billingAttemptMarkup(attempt)}${modalForm('billing-payment-recover', `<div class="modal-tip">Copy the checkout session ID from the ${escape(paymongoDashboardLabel(attempt.mode))}. The server verifies this existing session against your saved invoice and payment mode. No new checkout is created.</div><div class="form-grid" style="margin-top:18px">${field('Checkout session ID', 'session_id', '', 'text', 'required maxlength="93" pattern="cs_[A-Za-z0-9_]{1,90}" autocomplete="off" spellcheck="false"', 'Starts with cs_. Use the session matching the saved reference above.', true)}</div>`, 'Recover checkout', `data-id="${number(id)}"`)}</div>`);
    }

    function billingAttemptHistory(data) {
        const attempts = data.payment_attempts || [];
        if (!attempts.length && billingConfiguration().provider !== 'paymongo') { return ''; }
        const note = number(data.payment_attempt_total) > attempts.length ? `Latest ${count(attempts.length)} of ${count(data.payment_attempt_total)} attempts, independently of the recent invoice list.` : 'Server-recorded checkouts, labeled by payment mode. A waiting or unknown attempt is not a confirmed payment.';
        return `<details class="panel financial-breakdown billing-history" data-billing-history ${state.billingHistoryOpen ? 'open' : ''}><summary><span><strong>Checkout history</strong><small>${escape(note)}</small></span><span class="financial-detail-toggle" aria-hidden="true">${icon('plus')}</span></summary>${attempts.length ? `<div class="table-wrap"><table><thead><tr><th>Invoice / reference</th><th>Amount / mode</th><th>Status</th><th>Started · Manila time</th><th>Actions</th></tr></thead><tbody>${attempts.map(attempt => `<tr><td><strong>${escape(data.invoices.find(invoice => number(invoice.id) === number(attempt.invoice_id))?.number || `Invoice #${number(attempt.invoice_id)}`)}</strong><small class="table-secondary">${escape(attempt.reference)}</small></td><td>${money(attempt.amount)}<small class="table-secondary">${escape(paymongoSourceLabel(attempt.mode))}</small></td><td>${billingAttemptBadge(attempt)}</td><td>${time(attempt.created_at)}</td><td>${button('View attempt', 'billing-attempt-details', 'small', '', `data-id="${number(attempt.id)}"`)}</td></tr>`).join('')}</tbody></table></div>` : empty('No checkout attempts yet.', 'Prepare hosted checkout from an open invoice after payment configuration is complete.', '', true, 'card')}</details>`;
    }

    function billingPaymentControls(invoice) {
        const config = billingConfiguration();
        const attempt = latestBillingAttempt(invoice);
        const disabled = state.billingBusy || state.billingLoading;
        if (attempt && config.provider === 'paymongo' && !billingAttemptModeMatches(attempt, config)) {
            return `<div class="billing-payment-box"><div class="billing-payment-heading"><strong>${escape(paymongoSourceLabel(attempt.mode))} · recorded checkout</strong>${billingAttemptBadge(attempt)}</div><p>This historical record uses a different payment mode from the current configuration. It stays visible, but its checkout cannot be opened, recovered, or rechecked in this mode.</p></div>`;
        }
        const recover = billingNeedsSessionRecovery(attempt) ? button('Recover checkout', 'billing-payment-recover', 'primary', '', `data-id="${number(invoice.id)}" ${disabled || config.provider !== 'paymongo' || !config.configured ? 'disabled' : ''}`) : '';
        if (invoice.status !== 'open') {
            if (!attempt) { return ''; }
            return `<div class="billing-payment-box"><div class="billing-payment-heading"><strong>${escape(paymongoSourceLabel(attempt.mode))} · recorded checkout</strong>${billingAttemptBadge(attempt)}</div><p>${attempt.status === 'needs_review' ? 'This recorded payment needs review. Rechecking does not assume subscription access or make a new charge.' : 'This invoice is already paid or closed. You can verify its existing attempt; a new checkout cannot be started here.'}</p>${state.billingPaymentMessages[invoice.id] ? `<p class="billing-payment-result" role="status">${escape(state.billingPaymentMessages[invoice.id])}</p>` : ''}${config.provider !== 'paymongo' || !config.configured ? `<p class="billing-setup-note">${escape(config.reason || 'Payment rechecking is not enabled in the current configuration.')}</p>` : ''}<div class="billing-payment-actions">${recover}${button('Recheck payment', 'billing-payment-recheck', 'subtle', 'refresh', `data-id="${number(invoice.id)}" ${disabled || config.provider !== 'paymongo' || !config.configured ? 'disabled' : ''}`)}</div></div>`;
        }
        if (config.provider !== 'paymongo') {
            return state.billing.simulation_enabled ? modalForm('simulate-payment', '<div class="modal-tip">This records a simulated payment and starts a simulated paid period. No money moves and no provider is contacted.</div>', 'Simulate payment', `data-id="${number(invoice.id)}" data-key="${uuid()}"`) : '<p class="modal-tip">Payments are not enabled for this billing configuration.</p>';
        }
        const url = safeBillingCheckoutUrl(state.billingPaymentUrls[invoice.id]);
        const mode = attempt?.mode || config.mode;
        const mayStart = !attempt || ['failed', 'expired'].includes(attempt.status);
        const canStart = config.checkout_enabled && !disabled && !state.billingNeedsRecheck[invoice.id] && mayStart;
        const startLabel = ['failed', 'expired'].includes(attempt?.status) ? mode === 'live' ? 'Start checkout again' : 'Start test checkout again' : mode === 'live' ? 'Pay with PayMongo (live)' : 'Pay with PayMongo (test)';
        const note = billingNeedsSessionRecovery(attempt) ? `Checkout creation was not confirmed. Recover its session from the ${paymongoDashboardLabel(mode)}; starting another checkout is blocked.` : state.billingNeedsRecheck[invoice.id] ? 'The previous request could not be confirmed. Recheck this invoice before continuing.' : attempt?.status === 'unknown' ? 'The existing session needs confirmation. Recheck its payment status; no new checkout will be created.' : attempt?.status === 'needs_review' ? 'This attempt needs review. Recheck for a confirmed server result; subscription access has not been assumed.' : attempt?.status === 'pending' ? 'Checkout is waiting for payment. Open the saved link, or recheck to retrieve its current status.' : mode === 'live' ? 'Live checkout collects real money for this invoice. Review the amount before paying. This does not set up automatic charges.' : 'This opens hosted test checkout for one invoice. No live payment is collected and no automatic charges are set up.';
        return `<div class="billing-payment-box"><div class="billing-payment-heading"><strong>${escape(paymongoCheckoutLabel(mode))}</strong>${attempt ? billingAttemptBadge(attempt) : statusBadge(paymongoModeLabel(mode), mode === 'live' ? 'green' : 'amber')}</div><p>${escape(note)}</p>${paymongoMethodLabels(config.payment_methods) ? `<p>Configured methods: ${escape(paymongoMethodLabels(config.payment_methods))}. Availability on the hosted page depends on the provider account.</p>` : ''}${!config.checkout_enabled ? `<p class="billing-setup-note">${escape(config.reason || 'Checkout is not configured yet. Ask the workspace owner to complete private configuration.')}</p>` : ''}${config.webhook_configured === false && config.configured ? '<p>Automatic payment confirmation is not configured. Use Recheck payment after checkout.</p>' : ''}${state.billingPaymentMessages[invoice.id] ? `<p class="billing-payment-result" role="status">${escape(state.billingPaymentMessages[invoice.id])}</p>` : ''}<div class="billing-payment-actions">${url && attempt?.status === 'pending' && !state.billingNeedsRecheck[invoice.id] ? `<a class="button primary" href="${escape(url)}" target="_blank" rel="noopener noreferrer">Open ${escape(paymongoCheckoutLabel(mode))}</a>` : recover || (mayStart ? button(startLabel, 'billing-payment-start', 'primary', 'card', `data-id="${number(invoice.id)}" ${!canStart ? 'disabled' : ''}`) : '')}${button('Recheck payment', 'billing-payment-recheck', 'subtle', 'refresh', `data-id="${number(invoice.id)}" ${disabled || !config.configured ? 'disabled' : ''}`)}</div><small>Only a confirmed server result marks the invoice paid. Returning from checkout is informational.</small></div>`;
    }

    async function paymongoInvoiceAction(id, action, payload = {}, recoveryForm = null) {
        if (!isAdmin() || state.billingBusy || state.billingLoading || !Number.isSafeInteger(number(id)) || number(id) <= 0) { return; }
        id = number(id);
        const invoice = state.billing?.invoices.find(record => number(record.id) === id);
        const config = billingConfiguration();
        const existingAttempt = billingAttemptForInvoice(id);
        if (config.provider === 'paymongo' && !billingAttemptModeMatches(existingAttempt, config)) {
            state.billingActionError = 'This recorded attempt uses a different payment mode. It cannot be verified with the current configuration.';
            state.billingPaymentMessages[id] = state.billingActionError;
            if (state.billingReturn?.invoice_id === id) { state.billingReturnMessage = state.billingActionError; }
            if (state.section === 'billing') { renderSection(true); }
            refreshBillingDialog(id);
            toast(state.billingActionError, true);
            return;
        }
        if (action === 'checkout') {
            if (config.provider !== 'paymongo' || !config.checkout_enabled || !invoice || invoice.status !== 'open') { return; }
            const attempt = latestBillingAttempt(invoice);
            if (state.billingNeedsRecheck[id] || (attempt && !['failed', 'expired'].includes(attempt.status))) { return; }
        }
        if (action === 'recover') {
            if (config.provider !== 'paymongo' || !config.configured || !billingNeedsSessionRecovery(billingAttemptForInvoice(id))) { return; }
            payload = {session_id: String(payload.session_id || '').trim()};
            if (!/^cs_[A-Za-z0-9_]{1,90}$/.test(payload.session_id)) {
                const error = recoveryForm?.querySelector('.form-error');
                if (error) { error.textContent = `Enter the checkout session ID starting with cs_ from the ${paymongoDashboardLabel(existingAttempt?.mode || config.mode)}.`; error.hidden = false; }
                return;
            }
        }
        const modalInvoice = modal.open && modalContent.querySelector(`[data-billing-invoice="${id}"]`);
        const recoveryControls = recoveryForm ? [...recoveryForm.querySelectorAll('button, input')] : [];
        const recoverySubmit = recoveryForm?.querySelector('button[type="submit"]');
        const recoverySubmitContent = recoverySubmit?.innerHTML;
        let recovered = false;
        const wasProtected = Boolean(state.billingNeedsRecheck[id]);
        state.billingBusy = true; state.billingActionError = null; state.billingRequest += 1;
        markBillingRecheck(id, true); delete state.billingPaymentUrls[id];
        state.billingPaymentMessages[id] = action === 'checkout' ? config.mode === 'live' ? 'Preparing live checkout…' : 'Preparing test checkout…' : action === 'recover' ? 'Verifying the existing checkout session…' : 'Checking the payment with the server…';
        recoveryControls.forEach(control => { control.disabled = true; });
        if (recoverySubmit) { recoverySubmit.textContent = 'Verifying checkout…'; }
        if (recoveryForm) { recoveryForm.querySelector('.form-error').hidden = true; }
        if (state.section === 'billing') { renderSection(true); }
        if (modalInvoice) { refreshBillingDialog(id); }
        try {
            const result = await api(`/billing/invoices/${id}/paymongo-${action}`, 'POST', payload);
            if (!result.billing?.subscription || !Array.isArray(result.billing.invoices)) { throw new Error('The payment response could not be confirmed. Recheck this invoice before trying again.'); }
            applyBillingState(result.billing); state.activity = null;
            const attempt = result.attempt;
            markBillingRecheck(id, ['creating', 'unknown'].includes(attempt?.status));
            recovered = action === 'recover' && Boolean(attempt?.session_id);
            const checkoutUrl = safeBillingCheckoutUrl(result.checkout_url);
            if (checkoutUrl && attempt?.status === 'pending') { state.billingPaymentUrls[id] = checkoutUrl; }
            state.billingPaymentMessages[id] = result.message || 'The server payment status has been refreshed.';
            if (recoveryForm && !recovered) { const error = recoveryForm.querySelector('.form-error'); error.textContent = state.billingPaymentMessages[id]; error.hidden = false; }
            if (state.billingReturn?.invoice_id === id) { state.billingReturnMessage = state.billingPaymentMessages[id]; }
            toast(state.billingPaymentMessages[id], attempt?.status === 'failed' || attempt?.status === 'needs_review');
        } catch (failure) {
            const uncertain = !failure.status || failure.status >= 500;
            if (!uncertain && (action === 'checkout' || [403, 404].includes(failure.status) || (!wasProtected && !config.configured && failure.status === 422))) { markBillingRecheck(id, false); }
            state.billingActionError = uncertain ? 'This payment request could not be confirmed. Recheck the same invoice before starting another checkout.' : failure.message;
            state.billingPaymentMessages[id] = state.billingActionError;
            if (recoveryForm) { const error = recoveryForm.querySelector('.form-error'); error.textContent = state.billingActionError; error.hidden = false; }
            if (state.billingReturn?.invoice_id === id) { state.billingReturnMessage = state.billingActionError; }
            toast(state.billingActionError, true);
        } finally {
            state.billingBusy = false;
            recoveryControls.forEach(control => { control.disabled = false; });
            if (recoverySubmit) { recoverySubmit.innerHTML = recoverySubmitContent; }
            if (state.section === 'billing') { renderSection(true); }
            refreshBillingDialog(id);
            if (recovered && modal.open && modalContent.querySelector(`[data-billing-recovery="${id}"]`)) {
                const record = state.billing?.invoices.find(invoice => number(invoice.id) === id);
                if (record) { invoiceModal(record); } else { billingAttemptModal(billingAttemptForInvoice(id)); }
            }
        }
    }

    function renderBilling() {
        const data = state.billing;
        const top = heading('Billing & plans', sections.billing[1], button('Refresh billing', 'refresh-billing', 'subtle', 'refresh', state.billingLoading || state.billingBusy ? 'disabled' : ''), '', 'analytics-heading workspace-heading');
        if (!data) { return `${top}${state.billingError ? `<div class="form-error">${escape(state.billingError)}</div>${button('Try again', 'refresh-billing', 'primary')}` : '<div class="panel quiet-state"><span class="loading-line" style="display:block;margin:15px auto"></span><h3>Opening your billing records…</h3></div>'}`; }
        const sub = data.subscription;
        const current = data.plans.find(plan => plan.id === sub.plan);
        const unlimited = value => value == null ? 'Unlimited' : count(value);
        const limitLabel = (value, singular, plural) => `${unlimited(value)} ${number(value) === 1 && value != null ? singular : plural}`;
        const exceedsPlan = plan => (plan.limits.locations != null && number(data.usage.locations) > number(plan.limits.locations)) || (plan.limits.team_members != null && number(data.usage.team_members) > number(plan.limits.team_members));
        const limits = data.usage.limits || current?.limits || {};
        const status = {trialing: 'Free trial', active: 'Active', past_due: 'Renewal due', canceled: 'Canceled'}[sub.status] || sub.status;
        const period = sub.current_period_end || sub.trial_ends_at;
        const openInvoice = data.invoices.find(invoice => invoice.status === 'open');
        const mayInvoice = sub.status !== 'active' || (period && new Date(period) <= new Date());
        const config = billingConfiguration();
        const paymongoSelected = config.provider === 'paymongo';
        const recoveryIds = [...new Set([...Object.keys(state.billingNeedsRecheck).map(Number), ...(data.payment_attempts || []).filter(billingNeedsSessionRecovery).map(attempt => number(attempt.invoice_id))])].filter(id => billingAttemptModeMatches(billingAttemptForInvoice(id), config));
        const recoveryNotice = recoveryIds.length ? `<div class="sample-banner pending-sale-banner billing-recovery-notice">${icon('alert')}<span><strong>A payment request needs confirmation.</strong> Recheck the invoice, or recover its existing session from the matching PayMongo dashboard mode.</span>${recoveryIds.map(id => { const recover = billingNeedsSessionRecovery(billingAttemptForInvoice(id)); return button(`${recover ? 'Recover' : 'Recheck'} ${escape(data.invoices.find(invoice => number(invoice.id) === id)?.number || `invoice #${id}`)}`, recover ? 'billing-payment-recover' : 'billing-payment-recheck', 'small', recover ? '' : 'refresh', `data-id="${id}" ${state.billingBusy || state.billingLoading || !config.configured ? 'disabled' : ''}`); }).join('')}</div>` : '';
        return `${top}<div class="sample-banner billing-mode-notice">${icon('info')}<span><strong>${paymongoSelected ? `PayMongo · ${escape(paymongoModeLabel(config.mode))}.` : 'Local billing simulation.'}</strong> ${escape(config.notice || (paymongoSelected ? billingModeNotice(config) : data.notice || 'Payments are simulated locally. No money moves and no payment provider is contacted.'))}</span></div>${paymongoSelected && !config.checkout_enabled ? `<div class="sample-banner billing-setup-notice">${icon('info')}<span><strong>Payment checkout needs configuration.</strong> ${escape(config.reason || 'Ask the workspace owner to complete private payment configuration.')}</span></div>` : ''}${state.billingReturnMessage ? `<div class="sample-banner billing-return-notice" role="status">${icon('info')}<span>${escape(state.billingReturnMessage)} Browser return links never confirm payment.</span></div>` : ''}${recoveryNotice}${state.billingError ? `<div class="form-error" role="alert">${escape(state.billingError)} Your last successful billing records remain visible.</div>` : ''}${state.billingActionError ? `<div class="form-error" role="alert">${escape(state.billingActionError)}</div>` : ''}<section class="panel billing-summary"><div><h2>${escape(current?.name || sub.plan)} <span class="badge ${sub.can_write === false ? 'amber' : 'green'}">${escape(status)}</span></h2><p>${escape(sub.billing_cycle === 'annual' ? 'Annual' : 'Monthly')} billing · ${sub.status === 'trialing' ? 'Trial ends' : 'Period ends'} ${date(period, {year: 'numeric'})}</p>${sub.pending_plan ? `<p class="billing-scheduled">Next renewal: ${escape(data.plans.find(plan => plan.id === sub.pending_plan)?.name || sub.pending_plan)} · ${escape(sub.pending_billing_cycle)}.</p>` : ''}${sub.cancel_at_period_end ? `<p class="billing-scheduled">Cancellation scheduled for ${date(period, {year: 'numeric'})}. Your records stay available.</p>` : ''}<p class="billing-access">${sub.demo_exempt || subscription().demo_exempt ? 'This sample workspace remains available for practice. Plan limits still apply.' : sub.can_write === false ? 'Sales, catalog and stock changes are paused. Returns and exports remain available.' : `Sales and stock changes are available${sub.access_ends_at ? ` through ${date(sub.access_ends_at, {year: 'numeric'})}` : ''}.`}</p></div><div class="billing-summary-actions">${button(openInvoice ? 'View open invoice' : 'Create invoice', openInvoice ? 'invoice-details' : 'billing-checkout', 'primary', 'receipt', openInvoice ? `data-id="${openInvoice.id}" ${state.billingBusy || state.billingLoading ? 'disabled' : ''}` : `${!mayInvoice || state.billingBusy || state.billingLoading ? 'disabled' : ''}`)}${button(sub.cancel_at_period_end || sub.status === 'canceled' ? 'Resume renewal' : 'Cancel renewal', sub.cancel_at_period_end || sub.status === 'canceled' ? 'billing-resume' : 'billing-cancel', 'subtle', '', state.billingBusy || state.billingLoading ? 'disabled' : '')}<small>${!mayInvoice ? 'Your paid period is still active. Renewal opens when it ends.' : paymongoSelected ? 'A paid period starts only after server-confirmed payment. Invoice checkouts are one-time payments.' : 'An invoice starts a paid period only after a recorded simulated payment.'}</small></div></section><div class="billing-usage"><div><strong>${count(data.usage.locations)} / ${unlimited(limits.locations)}</strong><span>Locations</span></div><div><strong>${count(data.usage.team_members)} / ${unlimited(limits.team_members)}</strong><span>Active team members</span></div></div><div class="billing-plan-heading"><div><h2>Subscription plans</h2><p>Changing an active paid plan schedules it for the next renewal.</p></div><div class="cycle-switch">${['monthly', 'annual'].map(cycle => `<button data-action="billing-cycle" data-cycle="${cycle}" class="${state.billingCycle === cycle ? 'active' : ''}" aria-pressed="${state.billingCycle === cycle}">${cycle === 'annual' ? 'Annual' : 'Monthly'}</button>`).join('')}</div></div><div class="billing-plans">${data.plans.map(plan => `<article class="panel billing-plan ${plan.id === sub.plan ? 'selected' : ''}">${plan.id === sub.plan ? '<span class="badge">Current plan</span>' : ''}<h3>${escape(plan.name)}</h3><div class="billing-price">${money(state.billingCycle === 'annual' ? plan.annual_price : plan.monthly_price)}<small>/ ${state.billingCycle === 'annual' ? 'year' : 'month'}</small></div><ul><li>${limitLabel(plan.limits.locations, 'location', 'locations')}</li><li>${limitLabel(plan.limits.team_members, 'active team member', 'active team members')}</li><li>Inventory, checkout and reports</li></ul>${exceedsPlan(plan) ? '<p class="plan-limit-reason">Your current locations or active team exceed this plan’s limits.</p>' : ''}${button(exceedsPlan(plan) ? 'Usage exceeds this plan' : plan.id === sub.plan && state.billingCycle === sub.billing_cycle && !sub.pending_plan ? 'Your current plan' : sub.status === 'active' ? 'Schedule this plan' : 'Choose this plan', 'billing-plan', 'primary', '', `data-plan="${escape(plan.id)}" ${state.billingBusy || state.billingLoading || exceedsPlan(plan) || (plan.id === sub.plan && state.billingCycle === sub.billing_cycle && !sub.pending_plan) ? 'disabled' : ''}`)}</article>`).join('')}</div><section class="panel recent-sales billing-invoices"><div class="panel-header"><div><h2>Invoices</h2><p>${number(data.invoice_total) > data.invoices.length ? `Latest ${count(data.invoices.length)} of ${count(data.invoice_total)} invoices. Export for the full history.` : 'Live payments, test confirmations, and local simulations are labeled separately.'}</p></div>${button('Export invoice CSV', 'export-invoices', 'small', 'download')}</div>${data.invoices.length ? `<div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Plan / period</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead><tbody>${data.invoices.map(invoice => `<tr><td><strong>${escape(invoice.number)}</strong><small class="table-secondary">Due ${date(invoice.due_at)}</small></td><td>${escape(data.plans.find(plan => plan.id === invoice.plan)?.name || invoice.plan)}<small class="table-secondary">${escape(invoice.billing_cycle)} · ${invoice.period_start && invoice.period_end ? `${date(invoice.period_start)}–${date(invoice.period_end)}` : 'Starts after confirmed payment'}</small></td><td>${money(invoice.amount)}</td><td><div class="billing-invoice-status">${invoiceStatusBadge(invoice)}${statusBadge(invoiceSourceLabel(invoice), invoiceSourceColor(invoice))}</div></td><td>${button('View invoice', 'invoice-details', 'small', '', `data-id="${invoice.id}"`)}</td></tr>`).join('')}</tbody></table></div>` : empty('No invoices yet.', 'Create an invoice when you are ready to move from your trial to a confirmed paid period.', '', true, 'receipt')}</section>${billingAttemptHistory(data)}${help('Plan limits apply to new locations and active team members. Canceling renewal keeps your records and access until the stated end date. Invoice payments are one-time; no automatic provider charge is set up.', 'billing')}`;
    }

    function billingActionModal(action, planId = null) {
        const data = state.billing; if (!data || state.billingBusy || state.billingLoading) { return; }
        const config = billingConfiguration();
        const paymongoSelected = config.provider === 'paymongo';
        const plan = data.plans.find(plan => plan.id === planId);
        const copy = {
            'billing-checkout': ['Create invoice', 'Creating an invoice does not make a payment.', paymongoSelected ? `This creates one open invoice for your selected plan. Open it to prepare ${paymongoCheckoutLabel(config.mode)}; only server-confirmed payment starts a paid period.` : 'This creates one open invoice for your selected plan. Record its simulated payment to begin a paid period.', 'Create invoice'],
            'billing-cancel': ['Cancel renewal?', 'Your data remains in this workspace.', 'Your current paid period or trial stays available until its end date. No further paid period is requested by this workspace. This does not refund an earlier payment.', 'Cancel renewal'],
            'billing-resume': ['Resume renewal', 'Resume renewal for this workspace.', 'This clears a scheduled cancellation or opens renewal again after access has ended. It does not make a payment.', 'Resume renewal'],
            'billing-plan': ['Change subscription plan', `${plan?.name || ''} · ${state.billingCycle}`, data.subscription.status === 'active' ? 'This change is scheduled for the next renewal. Your current paid period stays in place.' : 'This updates your selected plan. Your original trial end date stays in place.', data.subscription.status === 'active' ? 'Schedule plan' : 'Choose plan'],
        }[action];
        if (!copy) { return; }
        openModal(copy[0], escape(copy[1]), modalForm(action, `<p class="danger-copy">${escape(copy[2])}</p><div class="modal-tip" style="margin-top:18px">${escape(paymongoSelected ? billingModeNotice(config) : 'Local payment simulation. No money moves.')}</div>`, copy[3], planId ? `data-plan="${escape(planId)}" data-cycle="${state.billingCycle}"` : ''));
    }

    function invoiceModal(invoice) {
        if (!invoice) { return; }
        const attempts = billingAttempts(invoice);
        const history = attempts.length ? `<section class="billing-attempt-history"><h3>Payment attempt history</h3><p class="billing-history-note">Recent business-wide attempts and this invoice’s latest attempt.</p>${attempts.map(billingAttemptMarkup).join('')}</section>` : '';
        openModal(escape(invoice.number), 'Workspace billing record · not a provider-issued tax invoice.', `<div class="billing-invoice" data-billing-invoice="${number(invoice.id)}"><div class="purchase-order-items"><div class="receipt-line"><span>Plan</span><strong>${escape(state.billing.plans.find(plan => plan.id === invoice.plan)?.name || invoice.plan)}</strong></div><div class="receipt-line"><span>Billing cycle</span><strong>${escape(invoice.billing_cycle)}</strong></div><div class="receipt-line"><span>Period</span><strong>${invoice.period_start && invoice.period_end ? `${date(invoice.period_start, {year: 'numeric'})}–${date(invoice.period_end, {year: 'numeric'})}` : 'Starts after confirmed payment'}</strong></div><div class="receipt-line"><span>Status</span>${invoiceStatusBadge(invoice)}</div><div class="receipt-line"><span>Payment source</span>${statusBadge(invoiceSourceLabel(invoice), invoiceSourceColor(invoice))}</div><div class="receipt-line total"><span>Amount</span><strong>${money(invoice.amount)}</strong></div>${invoice.paid_at ? `<div class="receipt-line"><span>Recorded payment</span><strong>${time(invoice.paid_at)}</strong></div>` : ''}</div>${state.billingActionError ? `<div class="form-error" role="alert">${escape(state.billingActionError)}</div>` : ''}${billingPaymentControls(invoice)}${history}<div class="form-actions">${button('Close', 'close-modal')}</div></div>`);
    }

    async function saveBilling(form, path, payload = {}) {
        if (!isAdmin() || state.billingBusy || state.busy) { return; }
        if (form.dataset.form === 'simulate-payment' && billingConfiguration().provider === 'paymongo') { return; }
        state.billingBusy = true; state.busy = true; state.billingRequest += 1; state.billingLoading = false; state.billingActionError = null;
        const submit = form.querySelector('button[type="submit"]');
        const previous = submit?.innerHTML;
        const error = form.querySelector('.form-error');
        if (error) { error.hidden = true; }
        if (submit) { submit.disabled = true; submit.textContent = 'Saving…'; }
        try {
            const result = await api(path, 'POST', payload);
            if (!result.subscription || !Array.isArray(result.invoices)) { throw new Error('The billing response could not be confirmed. Refresh billing before trying again.'); }
            applyBillingState(result); state.activity = null;
            modal.close();
            toast(form.dataset.form === 'simulate-payment' ? 'Simulated payment recorded. No money moved.' : 'Billing records updated. This action did not make a payment.');
        } catch (failure) {
            if (error?.isConnected) { error.textContent = failure.message; error.hidden = false; }
            else { state.billingActionError = failure.message; toast(failure.message, true); }
        } finally {
            state.billingBusy = false; state.busy = false;
            if (submit?.isConnected) { submit.disabled = false; submit.innerHTML = previous; }
            if (state.section === 'billing') { renderSection(true); }
        }
    }

    async function loadCashSessions() {
        if (state.pendingRecordAction) { recordRecoveryModal(); return; }
        if (state.cashLoading) { return; }
        state.cashLoading = true; state.cashError = null;
        openModal('Cash session', `${escape(currentLocation()?.name)} · your own cash session`, '<div class="quiet-state"><span class="loading-line" style="display:block;margin:15px auto"></span><h3>Getting your cash session…</h3></div>');
        try { state.cashSessions = await api(`/cash-sessions?location_id=${state.location}`); }
        catch (failure) { state.cashError = failure.message; }
        finally { state.cashLoading = false; cashSessionModal(); }
    }

    function cashSessionModal() {
        const session = state.cashSessions?.current;
        const history = (state.cashSessions?.history || []).filter(entry => entry.status === 'closed');
        openModal('Cash session', `${escape(currentLocation()?.name)} · your own cash session`, `${state.cashError ? `<div class="form-error">${escape(state.cashError)}</div>${button('Try again', 'cash-sessions', 'primary')}` : session ? `<div class="cash-session-heading"><div>${statusBadge('Open session', 'green')}<h3>${escape(session.cashier_name)}</h3><small>Opened ${time(session.opened_at)}</small></div><strong>${money(session.expected_cash)}<small>Expected in drawer</small></strong></div><div class="purchase-order-items cash-session-totals">${[['Opening float', session.opening_float], ['Cash sales after change', session.cash_sales], ['Cash added', session.cash_in], ['Cash taken out', -number(session.cash_out)]].map(([label, amount]) => `<div class="receipt-line"><span>${label}</span><strong>${money(amount)}</strong></div>`).join('')}</div><div class="cash-session-actions">${button('Cash in / out', 'cash-adjust', '', 'cash', `data-id="${session.id}"`)}${button('Close & count', 'cash-close', 'primary', 'check', `data-id="${session.id}"`)}</div>${session.adjustments?.length ? `<div class="return-history"><h3>Drawer movements</h3>${session.adjustments.map(entry => `<div><strong>${entry.direction === 'in' ? '+' : '−'}${money(entry.amount)} · ${escape(entry.reason)}</strong><small>${time(entry.created_at)}</small></div>`).join('')}</div>` : ''}` : `<div class="quiet-state">${icon('cash')}<h3>A fresh cash session.</h3><p>Record the float in your drawer before the first sale.</p></div>${modalForm('cash-open', field('Opening float (₱)', 'opening_float', 0, 'number', 'required min="0" max="10000000" step="0.01"', 'Cash already in your drawer. Card and wallet sales are not added to cash.', true), 'Open cash session', `data-key="${uuid()}" data-location="${state.location}"`)}`}<div class="modal-tip" style="margin-top:19px">Cash sales link to your own open session at this location. A return does not automatically remove money from the drawer. Record any actual cash refund as Cash out. Checkout also works without an open session.</div>${history.length ? `<div class="return-history"><h3>Your recent closed sessions</h3>${history.slice(0, 10).map(entry => `<div><strong>${date(entry.closed_at)} · ${money(entry.counted_cash)} counted ${statusBadge(number(entry.variance) === 0 ? 'Balanced' : `${number(entry.variance) > 0 ? '+' : '−'}${money(Math.abs(number(entry.variance)))} variance`, number(entry.variance) === 0 ? 'green' : 'amber')}</strong><small>Opened ${time(entry.opened_at)} · expected ${money(entry.expected_cash)}${entry.close_reason ? `<br>${escape(entry.close_reason)}` : ''}</small></div>`).join('')}</div>` : ''}${session || state.cashError ? `<div class="form-actions">${button('Back to checkout', 'close-modal')}${button('Refresh cash', 'cash-sessions', 'subtle', 'refresh')}</div>` : ''}`);
    }

    function cashActionModal(session, action) {
        if (!session) { return; }
        if (state.pendingRecordAction) { recordRecoveryModal(); return; }
        const closing = action === 'cash-close';
        openModal(closing ? 'Close cash session' : 'Record cash movement', `Expected cash: ${money(session.expected_cash)}`, modalForm(closing ? 'cash-close' : 'cash-adjust', `<div class="form-grid">${closing ? field('Counted cash (₱)', 'counted_cash', '', 'number', 'required min="0" max="10000000" step="0.01"', 'Count the physical cash. The difference is saved as a variance.', true) : `${selectField('Movement', 'direction', '<option value="in">Cash in · add to drawer</option><option value="out">Cash out · remove from drawer</option>', '', true)}${field('Amount (₱)', 'amount', '', 'number', 'required min="0.01" max="10000000" step="0.01"', 'Record only money that physically moved.', true)}`}${field(closing ? 'Count note' : 'Reason', 'reason', '', 'text', `${closing ? '' : 'required'} maxlength="200"`, closing ? 'Optional. Explain any difference in the drawer count.' : 'For example: Cash refund for receipt SR-123.', true)}</div>`, closing ? 'Close cash session' : 'Record cash movement', `data-id="${session.id}" data-key="${uuid()}"`));
    }

    function cartTotals() {
        const subtotalCents = state.cart.reduce((total, item) => total + Math.round(number(productPrice(productById(item.product_id))) * 100) * item.quantity, 0);
        const percentage = (cents, percent) => Number((BigInt(cents) * BigInt(Math.round(percent * 100)) + 5000n) / 10000n);
        const discountCents = percentage(subtotalCents, state.discount);
        const taxPercent = number(state.data.settings?.tax_percent);
        const taxCents = percentage(subtotalCents - discountCents, taxPercent);
        return {subtotal: subtotalCents / 100, discount: discountCents / 100, taxPercent, tax: taxCents / 100, total: (subtotalCents - discountCents + taxCents) / 100};
    }

    function cartHtml() {
        const totals = cartTotals();
        const itemCount = state.cart.reduce((sum, item) => sum + item.quantity, 0);
        return `<aside class="cart-panel" aria-label="Current sale"><div class="cart-header"><h2>Current sale <span>(${itemCount})</span></h2>${state.cart.length ? '<button data-action="clear-cart">Clear cart</button>' : '<span>No items</span>'}</div><div class="cart-items">${state.cart.length ? state.cart.map(item => {
            const product = productById(item.product_id);
            if (!product) { return ''; }
            return `<div class="cart-row">${productArt(product, true)}<div><div class="cart-row-name">${escape(product.name)}</div><small>${money(productPrice(product))} each</small><div class="quantity-control"><button data-action="cart-minus" data-id="${product.id}" aria-label="Remove one ${escape(product.name)}">−</button><strong>${item.quantity}</strong><button data-action="add-cart" data-id="${product.id}" aria-label="Add one ${escape(product.name)}" ${item.quantity >= availableStock(product) ? 'disabled' : ''}>+</button></div></div><div class="cart-row-price">${money(number(productPrice(product)) * item.quantity)}</div></div>`;
        }).join('') : `<div class="cart-empty">${icon('cart')}<strong>Cart is empty</strong><p>Tap a product or scan its barcode to add it here.</p></div>`}</div><div class="cart-hold-actions">${button('Save cart', 'hold-cart', 'small', 'clock', `${!state.cart.length || state.pendingSale ? 'disabled' : ''}`)}${button(`Held carts (${state.heldCarts.length})`, 'held-carts', 'small subtle', 'cart')}</div><div class="cart-tier"><label for="cart-price-tier">Price tier</label><select id="cart-price-tier" ${state.pendingSale ? 'disabled' : ''}><option value="retail" ${state.priceTier === 'retail' ? 'selected' : ''}>Retail prices</option><option value="wholesale" ${state.priceTier === 'wholesale' ? 'selected' : ''}>Wholesale prices</option></select></div><div class="cart-options"><div><label for="cart-customer">Customer</label><select id="cart-customer"><option value="">Walk-in customer</option>${(state.data.customers || []).map(customer => `<option value="${customer.id}" ${String(customer.id) === String(state.customer) ? 'selected' : ''}>${escape(customer.name)}</option>`).join('')}</select></div><div><label for="cart-discount">Discount %</label><input id="cart-discount" type="number" min="0" max="100" step="1" value="${state.discount}" aria-label="Discount percentage"></div></div><div class="cart-totals"><div class="total-row"><span>Subtotal</span><b>${money(totals.subtotal)}</b></div>${totals.discount ? `<div class="total-row"><span>Discount (${state.discount}%)</span><b>−${money(totals.discount)}</b></div>` : ''}<div class="total-row"><span>Tax (${totals.taxPercent}%)</span><b>${money(totals.tax)}</b></div><div class="total-row grand-total"><span>Total due</span><b>${money(totals.total)}</b></div></div><div class="cart-footer"><button class="button primary" data-action="charge" ${!state.cart.length || (!canWrite() && !state.pendingSale) ? 'disabled' : ''}><span>Charge ${money(totals.total)}</span></button><p>Channel reservations are excluded. Stock updates when the sale completes.</p></div></aside>`;
    }

    function renderPos() {
        const categories = [...new Set(products().map(product => product.category).filter(Boolean))];
        return `${heading('Point of sale', sections.pos[1], button('Cash session', 'cash-sessions', '', 'cash') + button('Add customer', 'add-customer', 'subtle', 'people'), '', 'workspace-heading')}${sampleBanner()}<div class="pos-layout"><div class="pos-catalog">${searchToolbar('Search or scan a barcode…', false)}<div class="category-tabs" aria-label="Product categories">${['All products', ...categories].map(category => `<button type="button" data-action="category" data-category="${escape(category)}" class="${state.category === category ? 'active' : ''}" aria-pressed="${state.category === category}">${escape(category)}</button>`).join('')}</div><div class="product-grid">${filteredProducts().length ? filteredProducts().map(product => `<button class="product-card" data-action="add-cart" data-id="${product.id}" ${availableStock(product) <= 0 || !hasTierPrice(product) || !canWrite() ? 'disabled' : ''} aria-label="Add ${escape(product.name)}, ${hasTierPrice(product) ? money(productPrice(product)) : 'no wholesale price'}"><span class="add-mark" aria-hidden="true">+</span>${productArt(product)}<div class="product-info"><strong title="${escape(product.name)}">${escape(product.name)}</strong>${product.product_family_id ? `<span class="product-option-label">${escape(variantLabel(product.variant_options || {}))}</span>` : ''}<div class="product-meta"><span>${availableStock(product) > 0 ? `${availableStock(product)} available` : 'Unavailable'}</span><b>${hasTierPrice(product) ? money(productPrice(product)) : 'Not priced'}</b></div></div></button>`).join('') : empty('No matching products', 'Try another search or category, or add a product in Inventory.', canManage() ? button('Add product', 'add-product', 'primary small', 'plus') : '')}</div>${help('Scan with a keyboard-style barcode scanner, then press Enter. Tap any product to add it.', 'pos')}</div>${cartHtml()}</div>`;
    }

    function renderInventory() {
        const filtered = filteredProducts();
        return `${heading('Inventory', sections.inventory[1], canManage() ? button('Add variants', 'add-variants', '', 'stock') + button('Add product', 'add-product', 'primary', 'plus') : '', '', 'workspace-heading')}${searchToolbar()}<section class="panel">${filtered.length ? `<div class="table-wrap"><table><thead><tr><th>Product</th><th>Category / variant</th><th>Price</th><th>Physical</th><th>Reserved</th><th>Available</th><th>Reorder at</th><th>Status</th><th>Actions</th></tr></thead><tbody>${filtered.map(product => `<tr><td><div class="table-name">${productArt(product, true)}<div><strong>${escape(product.name)}</strong><small>${escape(product.sku)}</small></div></div></td><td><div class="table-name"><div><strong>${escape(product.category || 'Uncategorized')}</strong><small>${escape(product.variant || 'Standard')}</small></div></div></td><td>${money(product.price)}</td><td class="stock-qty ${availableStock(product) <= number(product.reorder_level) ? 'low' : ''}">${count(stock(product))}</td><td class="stock-reserved">${count(reservedStock(product))}</td><td class="stock-available">${count(availableStock(product))}</td><td>${count(product.reorder_level)}</td><td>${stock(product) === 0 ? statusBadge('Out of stock', 'red') : availableStock(product) === 0 ? statusBadge('Fully reserved', 'amber') : availableStock(product) <= number(product.reorder_level) ? statusBadge('Low available stock', 'amber') : statusBadge('In stock', 'green')}</td><td><div class="table-actions">${canManage() ? `<button data-action="edit-product" data-id="${product.id}">Edit</button>${product.product_family_id ? `<button data-action="manage-variants" data-family-id="${number(product.product_family_id)}">Manage variants</button>` : `<button data-action="create-variants" data-id="${product.id}">Create variants</button>`}<button data-action="adjust" data-id="${product.id}">Adjust</button><button data-action="transfer" data-id="${product.id}">Transfer</button>` : `<button data-action="inspect-product" data-id="${product.id}">View stock</button>`}</div></td></tr>`).join('')}</tbody></table></div>` : empty('No matching products', 'Try another search or category, or add a product to your catalog.', canManage() ? button('Add product', 'add-product', 'primary small', 'plus') : '', true)}<div class="access-note">Physical is stock on hand. Reserved is held for pending channel orders. Available is what your team can sell or transfer. Quantities shown for <strong>${escape(currentLocation()?.name)}</strong>. Choose a location at the top to see another store or channel.${!canManage() ? ' Only an administrator or manager can change inventory.' : ''}</div></section>${help('Adjust adds or removes units. Transfer moves existing units between locations without changing your total stock.', 'inventory')}${canManage() ? `<section class="panel" style="margin-top:22px"><div class="panel-header"><div><h2>Recent stock movements</h2><p>Sales, returns, deliveries, adjustments and transfers.</p></div><span class="period-label">Latest activity</span></div>${movementHtml()}</section>` : ''}`;
    }

    function movementHtml() {
        const movements = (state.data.movements || []).filter(movement => number(movement.location_id) === state.location).slice(0, 12);
        if (!movements.length) { return empty('No movements recorded yet.', 'Every sale, return and stock update leaves a record here.', '', true, 'transfer'); }
        return `<div class="movement-list">${movements.map(movement => {
            const product = productById(movement.product_id);
            const quantity = number(movement.quantity ?? movement.change ?? movement.delta);
            return `<div class="movement-row"><span class="movement-icon">${icon('transfer')}</span><div><strong>${escape(product?.name || movement.product_name || 'Product')}</strong><small>${escape(movement.reason || movement.type || 'Stock update')} · ${time(movement.created_at)}</small></div><b class="${quantity < 0 ? 'negative' : ''}">${quantity > 0 ? '+' : ''}${count(quantity)}</b></div>`;
        }).join('')}</div>`;
    }

    const commerceKinds = {web_store: 'Web store', marketplace: 'Marketplace', social_shop: 'Social shop'};
    const commerceStatuses = {synced: ['Synced', 'green'], failed: ['Sync failed', 'red'], paused: ['Paused', ''], stale: ['Needs sync', 'amber']};
    const commerceChannels = () => (state.commerce?.channels || []).filter(channel => number(channel.location_id) === state.location);
    const selectedCommerceChannel = () => commerceChannels().find(channel => number(channel.id) === number(state.commerceChannel));
    const commerceOrderCoverage = () => number(state.commerce?.order_total) > (state.commerce?.orders.length || 0) ? `All pending + latest ${count(state.commerce.history_limit || 200)} processed orders` : 'All recorded orders';
    const commerceOrders = () => (state.commerce?.orders || []).filter(order => number(order.channel_id) === number(state.commerceChannel));

    async function loadCommerce(render = true) {
        if (!canManage() || state.commerceLoading) { return; }
        state.commerceLoading = true; state.commerceError = null;
        if (render && state.section === 'channels') { renderSection(); }
        try {
            state.commerce = await api('/commerce');
            if (!selectedCommerceChannel()) { state.commerceChannel = number(commerceChannels()[0]?.id) || null; }
        } catch (failure) { state.commerceError = failure.message; }
        finally { state.commerceLoading = false; if (render && state.section === 'channels') { renderSection(true); } }
    }

    function channelStateBadge(channel) {
        const status = channel.enabled ? (channel.pending_sync && channel.sync_state === 'synced' ? 'stale' : channel.sync_state) : 'paused';
        const [label, color] = commerceStatuses[status] || ['Needs sync', 'amber'];
        return statusBadge(label, color);
    }

    function renderChannels() {
        const top = heading('Channels', sections.channels[1], button('Refresh channels', 'commerce-refresh', 'subtle', 'refresh') + button('Add channel', 'commerce-add', 'primary', 'plus', !canWrite() ? 'disabled' : ''), '', 'workspace-heading');
        if (!state.commerce) {
            return `${top}${state.commerceError ? `<div class="form-error" role="alert">${escape(state.commerceError)}</div>${button('Try again', 'commerce-refresh', 'primary')}` : '<div class="panel quiet-state"><span class="loading-line" style="display:block;margin:15px auto"></span><h3>Opening your channels…</h3></div>'}`;
        }
        const channels = commerceChannels();
        if (!selectedCommerceChannel()) { state.commerceChannel = number(channels[0]?.id) || null; }
        const channel = selectedCommerceChannel();
        const notice = `<div class="sample-banner commerce-notice">${icon('info')}<span><strong>Local commerce simulation.</strong> No external store or payment provider is connected. Stock is checked centrally for every order.</span></div>`;
        const onboarding = `<div class="commerce-onboarding"><article><span>01</span><h3>Add a channel</h3><p>Choose a web store, marketplace or social shop, then link its stock location.</p></article><article><span>02</span><h3>Sync the catalog</h3><p>Products map automatically by SKU. Available stock becomes the published quantity.</p></article><article><span>03</span><h3>Simulate an order</h3><p>Simulate a paid order, then import it into sales or cancel its reservation.</p></article></div>`;
        if (!channel) { return `${top}${notice}${state.commerceError ? `<div class="form-error">${escape(state.commerceError)}</div>` : ''}${onboarding}${empty('No channels at this location', `No channels use ${escape(currentLocation()?.name)} yet. Add one here, or choose another stock location at the top.`, button('Add your first channel', 'commerce-add', 'primary', 'plus', !canWrite() || !state.commerce.simulation_enabled ? 'disabled' : ''), false, 'channels')}${help('Channels share the selected location’s physical stock. Pending orders reserve units so POS and other channels cannot sell them twice.', 'channels')}`; }
        const orders = commerceOrders();
        const pending = orders.filter(order => order.status === 'pending');
        const canSimulate = Boolean(channel.enabled && channel.listings.some(listing => number(listing.available_quantity) > 0) && state.commerce.simulation_enabled && canWrite());
        return `${top}${notice}${state.commerceError ? `<div class="form-error">${escape(state.commerceError)}</div>` : ''}<section class="panel commerce-channel-panel"><div class="commerce-channel-top"><div><div class="channel-context">${escape(commerceKinds[channel.kind] || channel.kind)} · ${escape(locationName(channel.location_id))}</div><h2>${escape(channel.name)}</h2><p>${channel.last_synced_at ? `Last synced ${time(channel.last_synced_at)}` : 'Sync once to publish your first catalog.'}</p></div><div class="commerce-channel-picker"><label for="commerce-channel">Channel</label><select id="commerce-channel">${channels.map(item => `<option value="${item.id}" ${number(item.id) === number(channel.id) ? 'selected' : ''}>${escape(item.name)}</option>`).join('')}</select></div></div><div class="commerce-channel-status">${channelStateBadge(channel)}${channel.simulate_failure ? statusBadge('Failure practice on', 'amber') : ''}<span>${channel.last_error ? escape(channel.last_error) : channel.enabled ? 'Catalog maps to your central product SKUs.' : 'New orders are paused. Existing pending orders can still be imported or canceled.'}</span></div><div class="commerce-channel-actions">${button(channel.enabled && (channel.sync_state === 'failed' || channel.last_error) ? 'Retry sync' : 'Sync catalog', 'commerce-sync', 'primary', 'refresh', `data-id="${channel.id}" ${!channel.enabled || !state.commerce.simulation_enabled || !canWrite() ? 'disabled' : ''}`)}${button('Simulate paid order', 'commerce-order', '', 'cart', `data-id="${channel.id}" ${!canSimulate ? 'disabled' : ''}`)}${button('Manage channel', 'commerce-manage', 'subtle', '', `data-id="${channel.id}"`)}${button(channel.enabled ? 'Pause channel' : 'Resume channel', channel.enabled ? 'commerce-pause' : 'commerce-resume', 'subtle', '', `data-id="${channel.id}"`)}</div>${!channel.listings.length ? '<p class="commerce-next-step">Next step: Sync catalog to publish your products before simulating an order.</p>' : !channel.listings.some(listing => number(listing.available_quantity) > 0) ? '<p class="commerce-next-step">No unreserved stock is available. Receive stock or review pending orders before simulating more.</p>' : ''}</section><div class="commerce-metrics"><div><strong>${count(channel.listings.length)}</strong><span>Mapped products</span></div><div><strong>${count(pending.length)}</strong><span>Pending orders</span></div><div><strong>${count(pending.reduce((sum, order) => sum + order.items.reduce((total, item) => total + number(item.quantity), 0), 0))}</strong><span>Units reserved by this channel</span></div></div><div class="commerce-tabs" role="tablist" aria-label="Channel records">${[['catalog', 'Catalog'], ['orders', `Orders (${orders.length})`], ['syncs', 'Sync history']].map(([tab, label]) => `<button role="tab" id="commerce-tab-${tab}" aria-controls="commerce-records" aria-selected="${state.commerceTab === tab}" data-action="commerce-tab" data-tab="${tab}" class="${state.commerceTab === tab ? 'active' : ''}">${label}</button>`).join('')}</div><section class="panel commerce-records" id="commerce-records" role="tabpanel" aria-labelledby="commerce-tab-${state.commerceTab}">${state.commerceTab === 'orders' ? commerceOrderTable(orders) : state.commerceTab === 'syncs' ? commerceSyncTable(channel) : commerceCatalogTable(channel)}</section>${help('Physical is stock on hand; reserved is held for pending orders; available can be sold. The server checks available stock for every POS or channel order, even when published counts are stale.', 'channels')}`;
    }

    function commerceCatalogTable(channel) {
        const listings = channel.listings.filter(listing => `${listing.name} ${listing.sku}`.toLowerCase().includes(state.commerceSearch.toLowerCase()));
        return `<div class="commerce-catalog-toolbar"><div><h3>Channel catalog</h3><p>Published is the last sync snapshot. Central quantities reflect current stock.</p></div><div class="search-field">${icon('search')}<input id="commerce-search" type="search" value="${escape(state.commerceSearch)}" placeholder="Search product or SKU…" aria-label="Search channel catalog"></div></div>${listings.length ? `<div class="table-wrap"><table><thead><tr><th>Product / central SKU</th><th>Price snapshot</th><th>Published</th><th>Physical</th><th>Reserved</th><th>Available</th></tr></thead><tbody>${listings.map(listing => `<tr><td><div class="table-name">${productById(listing.product_id) ? productArt(productById(listing.product_id), true) : ''}<div><strong>${escape(listing.name)}</strong><small>${escape(listing.sku)} · product #${number(listing.product_id)}</small></div></div></td><td>${money(listing.price)}</td><td>${count(listing.published_quantity)}</td><td>${count(listing.on_hand ?? stock(productById(listing.product_id), channel.location_id))}</td><td class="stock-reserved">${count(listing.reserved_quantity)}</td><td class="stock-available">${count(listing.available_quantity)}</td></tr>`).join('')}</tbody></table></div>` : empty(state.commerceSearch ? 'No matching products.' : 'Your catalog is ready to publish.', state.commerceSearch ? 'Try another name or SKU.' : 'Sync the catalog to map all products. Add your first product in Inventory if the central catalog is empty.', !state.commerceSearch && products().length ? button('Sync catalog', 'commerce-sync', 'primary small', 'refresh', `data-id="${channel.id}" ${!channel.enabled || !canWrite() ? 'disabled' : ''}`) : '', true)}<div class="access-note">SKU mapping is automatic across the full central catalog. Product names, prices and stock are managed in Inventory.</div>`;
    }

    function commerceOrderTable(orders) {
        const shown = orders.filter(order => state.commerceOrderStatus === 'all' || order.status === state.commerceOrderStatus);
        if (!orders.length) { return empty('No channel orders', 'Simulate a paid order. Its units are reserved until you import it into sales or cancel the pending order.', button('Simulate paid order', 'commerce-order', 'primary small', 'cart', `data-id="${state.commerceChannel}" ${!selectedCommerceChannel()?.enabled || !selectedCommerceChannel()?.listings.length || !canWrite() ? 'disabled' : ''}`), true, 'receipt'); }
        return `<div class="panel-header"><div><h2>Orders & reservations</h2><p>Quotes keep the original server prices and tax.</p></div><span class="period-label">${commerceOrderCoverage()}</span></div><div class="commerce-order-filter"><label for="commerce-order-status">Show orders</label><select id="commerce-order-status">${[['all', 'All orders'], ['pending', 'Pending · reserved'], ['imported', 'Imported to sales'], ['canceled', 'Canceled']].map(([value, label]) => `<option value="${value}" ${state.commerceOrderStatus === value ? 'selected' : ''}>${label}</option>`).join('')}</select><span>${count(shown.length)} ${shown.length === 1 ? 'order' : 'orders'}</span></div>${!shown.length ? empty('No orders in this stage.', 'Choose All orders to see the rest of this channel’s recorded history.', '', true, 'receipt') : `<div class="table-wrap"><table><thead><tr><th>External reference</th><th>When</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead><tbody>${shown.map(order => `<tr><td><strong>${escape(order.external_reference)}</strong><small class="table-secondary">${itemsLabel(order.items.reduce((sum, item) => sum + number(item.quantity), 0))} · simulated paid</small></td><td>${time(order.created_at)}</td><td>${money(order.total)}</td><td>${statusBadge({pending: 'Reserved · pending', imported: 'Imported to sales', canceled: 'Canceled'}[order.status] || order.status, order.status === 'imported' ? 'green' : order.status === 'pending' ? 'amber' : '')}</td><td><div class="table-actions"><button data-action="commerce-order-details" data-id="${order.id}">View order</button>${order.status === 'pending' ? `<button data-action="commerce-import" data-id="${order.id}" ${!canWrite() ? 'disabled' : ''}>Import</button><button class="danger" data-action="commerce-cancel" data-id="${order.id}">Cancel</button>` : order.sale_id ? `<button data-action="commerce-sale" data-id="${order.sale_id}">Sale receipt</button>` : ''}</div></td></tr>`).join('')}</tbody></table></div>`}`;
    }

    function commerceSyncTable(channel) {
        const syncs = (state.commerce.syncs || []).filter(sync => number(sync.channel_id) === number(channel.id));
        if (!syncs.length) { return empty('No sync records', 'Sync your first catalog to see its result here. Failed practice runs stay in the history too.', '', true, 'refresh'); }
        return `<div class="panel-header"><div><h2>Sync history</h2><p>Failures can be fixed and retried with a fresh sync.</p></div><span class="period-label">Latest ${count(state.commerce.history_limit || 200)} business syncs</span></div><div class="table-wrap"><table><thead><tr><th>When</th><th>Result</th><th>Mapped products</th><th>Details</th></tr></thead><tbody>${syncs.map(sync => `<tr><td>${time(sync.created_at)}</td><td>${statusBadge(sync.status === 'succeeded' ? 'Succeeded' : 'Failed', sync.status === 'succeeded' ? 'green' : 'red')}</td><td>${count(sync.listing_count)}</td><td class="commerce-sync-message">${escape(sync.message)}</td></tr>`).join('')}</tbody></table></div>`;
    }

    function commerceReady(requireSimulation = true) {
        if (state.pendingRecordAction) { recordRecoveryModal(); return false; }
        if (!canManage()) { return false; }
        if (!state.commerce) { toast('Wait for the channel records to finish loading.', true); return false; }
        if (requireSimulation && !state.commerce.simulation_enabled) { toast('Commerce simulation is unavailable in this environment.', true); return false; }
        return true;
    }

    function commerceChannelForm(channel = null) {
        if (!commerceReady()) { return; }
        openModal(channel ? 'Edit channel' : 'Add simulated channel', channel ? escape(channel.name) : 'Local simulation. No credentials or external storefront required.', modalForm(channel ? 'commerce-edit' : 'commerce-create', `<div class="form-grid">${field('Channel name', 'name', channel?.name || '', 'text', 'required maxlength="100"', 'For example: Harper web shop.', true)}${channel ? `<div class="modal-tip full">${escape(commerceKinds[channel.kind])} · stock from ${escape(locationName(channel.location_id))}. Channel type and stock location stay fixed after creation.</div><label class="toggle-field full"><span><strong>Practice a sync failure</strong><p>Sync will fail locally until you turn this off. No real service is contacted.</p></span><input type="checkbox" name="simulate_failure" ${channel.simulate_failure ? 'checked' : ''}></label>` : `${selectField('Channel type', 'kind', Object.entries(commerceKinds).map(([kind, label]) => `<option value="${kind}">${label}</option>`).join(''), '', true)}${selectField('Shared stock location', 'location_id', locationOptions(), 'POS and other channels use the same available stock here.', true)}`}</div>`, channel ? 'Save channel' : 'Add channel', channel ? `data-id="${channel.id}"` : `data-key="${uuid()}"`));
    }

    function commerceActionModal(channel, action) {
        if (!channel || !commerceReady(action !== 'commerce-pause')) { return; }
        const copy = {
            'commerce-sync': ['Sync channel catalog', 'Sync catalog', 'Map the full central catalog by SKU and publish current available quantities. A failure practice run records an error without publishing new counts.'],
            'commerce-pause': ['Pause channel?', 'Pause channel', 'New simulated orders will stop. Existing reservations remain until their pending orders are imported or canceled.'],
            'commerce-resume': ['Resume channel', 'Resume channel', 'New simulated orders can use the shared location’s available stock again. Sync the catalog to refresh its published snapshot.'],
        }[action];
        if (!copy) { return; }
        openModal(copy[0], escape(channel.name), modalForm(action, `<p class="danger-copy">${escape(copy[2])}</p><div class="modal-tip" style="margin-top:18px">Local simulation only. Stock is checked centrally for every incoming order.</div>`, copy[1], `data-id="${channel.id}" data-key="${uuid()}"`));
    }

    function commerceOrderLine(channel) {
        return `<div class="commerce-order-line"><select name="sku" required aria-label="Central product SKU"><option value="">Choose product</option>${channel.listings.map(listing => `<option value="${escape(listing.sku)}" data-available="${number(listing.available_quantity)}" ${number(listing.available_quantity) <= 0 ? 'disabled' : ''}>${escape(listing.name)} · ${escape(listing.sku)} (${count(listing.available_quantity)} available)</option>`).join('')}</select><input name="quantity" type="number" min="1" max="100000" step="1" value="1" required aria-label="Order quantity"><button type="button" data-action="commerce-remove-line" aria-label="Remove order item">×</button></div>`;
    }

    function commerceOrderForm(channel) {
        if (!channel || !commerceReady()) { return; }
        if (!channel.enabled || !channel.listings.length) { toast('Resume and sync this channel before simulating an order.', true); return; }
        if (!channel.listings.some(listing => number(listing.available_quantity) > 0)) { toast('No unreserved stock is available. Receive stock or review pending orders first.', true); return; }
        openModal('Simulate paid order', escape(channel.name), modalForm('commerce-order', `<div class="modal-tip">This creates a simulated paid order and reserves its units. The server saves an immutable quote using current retail prices and tax. Import it to complete the stock deduction and sales record.</div><div class="form-grid">${field('External order reference', 'external_reference', `LOCAL-${Date.now().toString(36).toUpperCase()}`, 'text', 'required maxlength="100"', 'Unique for this channel. Use it to find the order later.', true)}</div><div class="commerce-order-labels"><span>Product / SKU</span><span>Qty.</span><span></span></div><div id="commerce-order-lines">${commerceOrderLine(channel)}</div>${button('Add another product', 'commerce-add-line', 'small subtle', 'plus')}<p class="commerce-quote-note">The saved order shows the server-calculated total before you import it. No card, wallet or bank account is charged.</p>`, 'Simulate paid order', `data-id="${channel.id}" data-key="${uuid()}"`));
    }

    function commerceOrderDetails(order, action = null) {
        if (!order) { return; }
        if (action && !commerceReady(action !== 'commerce-cancel')) { return; }
        const returning = action === 'commerce-cancel';
        const lines = `<div class="purchase-order-items">${order.items.map(item => `<div class="receipt-line"><div><strong>${escape(item.name)}</strong><small class="table-secondary">${count(item.quantity)} × ${money(item.unit_price)} · ${escape(item.sku)}</small></div><strong>${money(item.line_total)}</strong></div>`).join('')}<div class="receipt-line"><span>Subtotal</span><strong>${money(order.subtotal)}</strong></div><div class="receipt-line"><span>Tax (${number(order.tax_percent)}%)</span><strong>${money(order.tax)}</strong></div><div class="receipt-line total"><span>Original order total</span><strong>${money(order.total)}</strong></div></div>`;
        const explanation = action ? returning ? 'Cancel this pending order and release its reserved units. No physical stock deduction or real payment refund is made.' : 'Import this simulated paid order once into sales. Its reservation is released as the same units are deducted from physical stock. The original quote stays unchanged.' : order.status === 'pending' ? 'Stock is reserved while this order waits for import. It cannot be sold by POS or another channel.' : order.status === 'imported' ? `Imported as ${escape(order.sale_number || `sale #${order.sale_id}`)}. Process any later return in Reports.` : 'The pending order was canceled. Its reservation was released; this order did not deduct physical stock.';
        openModal(action ? returning ? 'Cancel pending order?' : 'Import channel order' : escape(order.external_reference), `${escape(order.channel_name)} · ${escape(locationName(order.location_id))} · simulated paid`, action ? modalForm(action, `<p class="danger-copy" style="margin-bottom:18px">${explanation}</p>${lines}<div class="modal-tip">No external store or payment provider is contacted.</div>`, returning ? 'Cancel pending order' : 'Import order', `data-id="${order.id}"`) : `${statusBadge({pending: 'Reserved · pending', imported: 'Imported to sales', canceled: 'Canceled'}[order.status] || order.status, order.status === 'pending' ? 'amber' : 'green')}${lines}<p class="danger-copy">${explanation}</p><div class="form-actions purchase-detail-actions">${button('Close', 'close-modal')}${order.status === 'pending' ? button('Cancel pending order', 'commerce-cancel', 'subtle', '', `data-id="${order.id}"`) + button('Import order', 'commerce-import', 'primary', '', `data-id="${order.id}" ${!canWrite() ? 'disabled' : ''}`) : order.sale_id ? button('View sale receipt', 'commerce-sale', 'primary', 'receipt', `data-id="${order.sale_id}"`) : ''}</div>`);
    }

    function selectCommerceContext(channelId, locationId) {
        const target = number(locationId);
        if (state.cart.length && target && target !== state.location) { return false; }
        if (target && locations().some(location => number(location.id) === target)) { state.location = target; locationSelect.value = target; }
        state.commerceChannel = number(channelId) || state.commerceChannel;
        const badge = document.getElementById('stock-badge'); badge.textContent = lowStock().length; badge.hidden = !lowStock().length || !alertsEnabled();
        return true;
    }

    async function finishCommerceAction(result, pending) {
        const channel = result?.channel || (result?.kind ? result : null);
        const order = result?.order || (result?.external_reference ? result : null);
        const selected = channel || order ? selectCommerceContext(channel?.id || order?.channel_id, channel?.location_id || order?.location_id) : pending?.context?.channel_id ? selectCommerceContext(pending.context.channel_id, pending.context.location_id) : true;
        await loadCommerce();
        if (order) { if (selected) { state.commerceTab = 'orders'; } if (state.section === 'channels') { renderSection(); } commerceOrderDetails(order); }
        if (!selected) { toast(`The action is saved at ${locationName(channel?.location_id || order?.location_id || pending?.context?.location_id)}. Your current POS cart stays at ${locationName(state.location)}. Save or finish that cart before switching locations.`); }
        if (result?.sync?.status === 'failed') { toast(result.sync.message || 'The simulated sync failed. Fix the channel’s failure setting and retry sync.', true); }
        else { toast(order?.status === 'imported' ? 'Order imported once. Its sale and stock movement are recorded.' : order?.status === 'canceled' ? 'Pending order canceled. Its reservation is released.' : order ? 'Simulated order saved. Its stock is reserved until import or cancellation.' : pending?.label === 'Channel sync' ? 'Catalog sync completed. Available quantities are published.' : pending?.label === 'Channel creation' ? 'Channel added.' : pending?.label === 'Channel pause' ? 'Channel paused. Existing reservations stay in place.' : pending?.label === 'Channel resume' ? 'Channel resumed. New orders use the shared available stock.' : 'Channel settings saved.'); }
    }

    async function commerceSaleReceipt(id, control) {
        if (!canManage()) { return; }
        control.disabled = true;
        try {
            const sale = state.data.sales.find(sale => number(sale.id) === number(id)) || await api(`/sales/${number(id)}`);
            receiptModal(sale.sale || sale);
        } catch (failure) { toast(failure.message, true); }
        finally { if (control.isConnected) { control.disabled = false; } }
    }

    async function submitCommerceAction(form, path, payload, label, method = 'POST') {
        const order = path.startsWith('/commerce/orders/') ? state.commerce?.orders.find(order => number(order.id) === number(form.dataset.id)) : null;
        const channelId = path.match(/^\/commerce\/channels\/(\d+)/)?.[1];
        const channel = state.commerce?.channels.find(channel => number(channel.id) === number(channelId || order?.channel_id));
        const pending = state.pendingRecordAction || {path, label, context: {channel_id: number(channelId || order?.channel_id) || null, location_id: number(payload.location_id || order?.location_id || channel?.location_id || state.location)}};
        const result = await saveRecordAction(form, path, payload, label, {method, quiet: true, context: pending.context});
        if (result) { await finishCommerceAction(result, pending); }
    }

    const orderStatuses = {draft: 'Draft · approval needed', ordered: 'Awaiting delivery', partially_received: 'Partly received', received: 'Received', cancelled: 'Canceled'};
    const orderMayReceive = order => ['ordered', 'partially_received'].includes(order.status);

    function renderPurchases() {
        const orders = (state.data.purchase_orders || []).filter(order => number(order.location_id) === state.location);
        const pending = orders.filter(orderMayReceive);
        const totals = state.data.purchase_order_stats_by_location?.[state.location] || {pending_deliveries: pending.length, drafts: orders.filter(order => order.status === 'draft').length, received: orders.filter(order => order.status === 'received').length};
        const historyNote = state.data.purchase_order_history?.truncated ? `<div class="sample-banner">${icon('info')}<span>All outstanding orders remain available. Completed and canceled history shows the latest ${count(state.data.purchase_order_history.processed_limit)} orders across locations; totals include the full history.</span></div>` : '';
        return `${heading('Purchasing', sections.purchases[1], button('Suppliers', 'suppliers', 'subtle', 'people') + button('Create order', 'create-order', 'primary', 'plus'), '', 'workspace-heading')}<div class="stats-grid purchase-stats">${metric('Pending deliveries', count(totals.pending_deliveries), 'Approved stock still outstanding', 'stock')}${metric('Drafts to approve', count(totals.drafts), 'Stock is added after delivery', 'receipt')}${metric('Received orders', count(totals.received), 'All ordered units received', 'check')}</div>${historyNote}<section class="panel reorder-nudge"><div><h2>Automated replenishment</h2><p>Set a supplier and target stock level. Generate purchase drafts when products reach their reorder level.</p></div>${button('Replenishment rules', 'reorder-rules', '', 'refresh')}</section>${orders.length ? `<div class="cards-grid">${orders.map(order => {
            const supplier = (state.data.suppliers || []).find(item => number(item.id) === number(order.supplier_id));
            const units = (order.items || []).reduce((total, item) => total + number(item.quantity), 0);
            const received = (order.items || []).reduce((total, item) => total + number(item.received_quantity ?? (order.status === 'received' ? item.quantity : 0)), 0);
            return `<article class="purchase-card"><div class="purchase-card-head"><strong>${escape(order.number || `PO-${order.id}`)}</strong>${statusBadge(orderStatuses[order.status] || order.status, order.status === 'received' ? 'green' : order.status === 'cancelled' ? '' : 'amber')}</div><h3>${escape(supplier?.name || 'Supplier')}</h3><p>${escape(locationName(order.location_id))} · ${date(order.created_at)}${order.source === 'replenishment' ? ' · Replenishment' : ''}</p><dl><div><dt>Units received</dt><dd>${count(received)} / ${count(units)}</dd></div><div><dt>Order total</dt><dd>${money(order.total)}</dd></div></dl><div class="purchase-card-actions">${button('View details', 'order-details', 'small', '', `data-id="${order.id}"`)}${orderMayReceive(order) ? button('Receive delivery', 'receive-order', 'primary small', 'check', `data-id="${order.id}" ${!canWrite() ? 'disabled' : ''}`) : order.status === 'draft' ? button('Approve order', 'approve-order', 'primary small', 'check', `data-id="${order.id}" ${!canWrite() ? 'disabled' : ''}`) : statusBadge(order.status === 'received' ? 'Stock updated' : 'Outstanding canceled', order.status === 'received' ? 'green' : '')}</div></article>`;
        }).join('')}</div>` : empty('No purchase orders', 'Choose a supplier, add products, and receive only what actually arrives. Drafts need approval before delivery.', button('Create your first order', 'create-order', 'primary', 'plus'))}${help('Receive delivery adds the quantities you enter. Later deliveries can complete the remaining order. Cancel outstanding keeps any stock already received.', 'purchases')}`;
    }

    async function loadReorderRules(show = true) {
        if (state.reorderLoading || !canManage()) { return; }
        state.reorderLoading = true; state.reorderError = null;
        if (show) { openModal('Replenishment rules', 'Rules create drafts. You approve the order before receiving stock.', '<div class="quiet-state"><span class="loading-line" style="display:block;margin:15px auto"></span><h3>Getting your rules…</h3></div>'); }
        try { const response = await api('/reorder-rules'); state.reorderRules = response.data || []; }
        catch (failure) { state.reorderError = failure.message; }
        finally { state.reorderLoading = false; if (show) { reorderRulesModal(); } }
    }

    function reorderRulesModal() {
        const rules = (state.reorderRules || []).filter(rule => number(rule.location_id) === state.location);
        openModal('Replenishment rules', `${escape(currentLocation()?.name)} · supplier orders start as drafts`, `${state.reorderError ? `<div class="form-error">${escape(state.reorderError)}</div>` : ''}<div class="reorder-modal-actions">${button('Add rule', 'add-reorder-rule', 'small primary', 'plus', !canWrite() ? 'disabled' : '')}${button('Generate drafts', 'generate-reorders', 'small', 'refresh', !canWrite() ? 'disabled' : '')}${button('Refresh rules', 'reorder-rules', 'small subtle')}</div>${rules.length ? `<div class="held-list">${rules.map(rule => `<article class="held-card"><div><strong>${escape(productById(rule.product_id)?.name || rule.product_name || 'Product')}</strong><small>${escape(state.data.suppliers.find(supplier => number(supplier.id) === number(rule.supplier_id))?.name || rule.supplier_name || 'Supplier')} · target ${count(rule.target_quantity)} units</small>${statusBadge(rule.enabled ? 'Enabled' : 'Paused', rule.enabled ? 'green' : '')}</div><div>${button('Edit rule', 'edit-reorder-rule', 'small', '', `data-id="${rule.id}"`)}${button(rule.enabled ? 'Pause' : 'Enable', 'toggle-reorder-rule', 'small subtle', '', `data-id="${rule.id}"`)}</div></article>`).join('')}</div>` : empty('Your next restock can start here.', 'Add a rule for a product, supplier and target quantity at this location.', '', true, 'stock')}<div class="modal-tip">Generation checks enabled rules across all locations. Available stock must be at or below the product’s reorder level. Outstanding quantities from all suppliers count toward the target. Nothing is sent to suppliers automatically.</div><div class="form-actions">${button('Back to purchasing', 'close-modal', 'primary')}</div>`);
    }

    function reorderRuleForm(rule = null) {
        if (!products().length) { toast('Add a product before setting a replenishment rule.', true); return; }
        if (!state.data.suppliers.length) { supplierModal(); return; }
        const choices = (items, selected) => items.map(item => `<option value="${item.id}" ${number(item.id) === number(selected) ? 'selected' : ''}>${escape(item.name)}</option>`).join('');
        openModal(rule ? 'Edit replenishment rule' : 'Add replenishment rule', 'Drafts are generated when stock reaches the product’s reorder level.', modalForm('reorder-rule', `<div class="form-grid">${rule ? `<div class="modal-tip full">${escape(productById(rule.product_id)?.name || 'Product')} · ${escape(locationName(rule.location_id))}</div>` : selectField('Product', 'product_id', choices(products(), null), '', true)}${rule ? '' : selectField('Location', 'location_id', locationOptions(), '', true)}${rule ? '' : selectField('Supplier', 'supplier_id', choices(state.data.suppliers, null), '', true)}${field('Target available stock quantity', 'target_quantity', rule?.target_quantity ?? 20, 'number', 'required min="1" max="1000000" step="1"', 'Use a target above the product’s reorder level. Available stock plus outstanding purchase orders count toward this number.', true)}<label class="toggle-field full"><span><strong>Enable this rule</strong><p>Pause it to stop generating future drafts.</p></span><input type="checkbox" name="enabled" ${!rule || rule.enabled ? 'checked' : ''}></label></div>`, rule ? 'Save rule' : 'Add rule', rule ? `data-id="${rule.id}"` : ''));
    }

    function renderCustomers() {
        const search = state.search.toLowerCase();
        const customers = (state.data.customers || []).filter(customer => `${customer.name} ${customer.email || ''} ${customer.phone || ''}`.toLowerCase().includes(search));
        return `${heading('Customers', sections.customers[1], button('Add customer', 'add-customer', 'primary', 'plus'), '', 'workspace-heading')}${searchToolbar('Search name, email or phone…', false)}${customers.length ? `<div class="cards-grid">${customers.map(customer => {
            const sales = (state.data.sales || []).filter(sale => number(sale.customer_id) === number(customer.id) && sale.status !== 'returned');
            return `<article class="customer-card"><div class="customer-top"><span class="customer-avatar">${escape(initials(customer.name))}</span><div><h3>${escape(customer.name)}</h3><p>Customer #${number(customer.id)}</p></div></div><dl><div><dt>Recorded purchases</dt><dd>${count(sales.length)}</dd></div><div><dt>Total spent</dt><dd>${money(sales.reduce((total, sale) => total + saleNet(sale), 0))}</dd></div></dl><div class="customer-contact"><span>${escape(customer.email || customer.phone || 'No contact details')}</span><button class="text-button" data-action="customer-sale" data-id="${customer.id}">New sale</button></div></article>`;
        }).join('')}</div>` : empty('No matching customers', state.search ? 'No customers match this search. Try a different name, email or phone number.' : 'Add a customer so your team can tag a sale and keep a useful purchase history.', button('Add customer', 'add-customer', 'primary', 'plus'), false, 'people')}${help('Customer tagging is optional at checkout. Customer history covers your permitted sales across all locations.', 'customers')}`;
    }

    const reportPresets = {today: 'Today', last7: 'Last 7 days', last30: 'Last 30 days', month: 'This month', all: 'All time', custom: 'Custom range'};
    const reportSources = {all: 'All sources', pos: 'Point of sale', channel: 'Simulated channels'};
    const financialBases = {activity: 'Sales & refund dates', sale_cohort: 'Original sale dates'};
    const manilaDay = value => new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date(value));
    const reportHour = hour => `${number(hour) % 12 || 12} ${number(hour) >= 12 ? 'PM' : 'AM'}`;
    const reportDayLabel = value => date(`${value}T00:00:00+08:00`, {year: 'numeric'});

    function reportPresetDates(preset) {
        const today = manilaDay(new Date());
        const start = new Date(`${today}T00:00:00Z`);
        if (preset === 'all') { return {from: '', to: ''}; }
        if (preset === 'month') { return {from: `${today.slice(0, 7)}-01`, to: today}; }
        start.setUTCDate(start.getUTCDate() - (preset === 'last7' ? 6 : preset === 'last30' ? 29 : 0));
        return {from: start.toISOString().slice(0, 10), to: today};
    }

    function initializeReportFilters() {
        if (!state.analyticsDraft) {
            state.analyticsDraft = {preset: 'last30', ...reportPresetDates('last30'), location_id: String(state.location), source: 'all', rank_by: 'units'};
        }
        return state.analyticsDraft;
    }

    function initializeFinancialFilters() {
        if (!state.financialDraft) {
            state.financialDraft = {preset: 'last30', ...reportPresetDates('last30'), location_id: String(state.location), source: 'all', basis: 'activity'};
        }
        return state.financialDraft;
    }

    function reportTabs() {
        if (!canManage()) { return ''; }
        return `<div class="reports-tabs" role="tablist" aria-label="Report type">${[['analytics', 'Sales analytics'], ['financial', 'Financial reports']].map(([tab, label]) => `<button type="button" role="tab" id="report-tab-${tab}" aria-controls="report-panel-${tab}" aria-selected="${state.reportView === tab}" tabindex="${state.reportView === tab ? 0 : -1}" data-action="report-tab" data-tab="${tab}" class="${state.reportView === tab ? 'active' : ''}">${label}</button>`).join('')}</div>`;
    }

    function reportPage(top, content) {
        const tab = state.reportView;
        if (!canManage()) { return `${top}${content}`; }
        const other = tab === 'financial' ? 'analytics' : 'financial';
        return `${top}${reportTabs()}<div class="report-panel ${tab === 'financial' ? 'financial-report' : ''}" id="report-panel-${tab}" role="tabpanel" aria-labelledby="report-tab-${tab}" tabindex="0">${content}</div><div id="report-panel-${other}" role="tabpanel" aria-labelledby="report-tab-${other}" hidden></div>`;
    }

    function selectReportView(tab, push = true) {
        state.saleRecordRequest += 1;
        state.reportView = tab === 'financial' && canManage() ? 'financial' : 'analytics';
        const url = `/app/reports${state.reportView === 'financial' ? '?view=financial' : ''}`;
        if (push && `${location.pathname}${location.search}` !== url) { history.pushState({}, '', url); }
        renderSection();
    }

    function analyticsQuery(filters, includeRank = true) {
        const params = new URLSearchParams();
        if (filters.from && filters.to) { params.set('from', filters.from); params.set('to', filters.to); }
        if (filters.location_id) { params.set('location_id', filters.location_id); }
        params.set('source', filters.source || 'all');
        if (includeRank) { params.set('rank_by', filters.rank_by || 'units'); }
        return params.toString();
    }

    function financialQuery(filters) {
        const params = new URLSearchParams(analyticsQuery(filters, false));
        params.set('basis', filters.basis || 'activity');
        return params.toString();
    }

    function invalidateReports() {
        state.analyticsRequest += 1; state.analytics = null; state.analyticsLoading = false; state.analyticsError = null;
        state.receiptHistoryRequest += 1; state.receiptHistory = null; state.receiptHistoryLoading = false; state.receiptHistoryError = null;
        state.financialRequest += 1; state.financial = null; state.financialLoading = false; state.financialError = null;
    }

    async function loadAnalytics(filters = null, silent = false) {
        const candidate = {...(filters || state.analyticsFilters || initializeReportFilters())};
        if ((candidate.from && !candidate.to) || (!candidate.from && candidate.to) || (candidate.from && candidate.from > candidate.to)) {
            state.analyticsRequest += 1; state.analyticsLoading = false;
            state.analyticsError = 'Choose both dates, with the end date on or after the start date.';
            if (state.section === 'reports' && state.reportView === 'analytics') { renderSection(true); }
            return;
        }
        const request = ++state.analyticsRequest;
        state.analyticsLoading = true; state.analyticsError = null;
        if (!silent && state.section === 'reports' && state.reportView === 'analytics') { renderSection(true); }
        try {
            const result = await api(`/reports/analytics?${analyticsQuery(candidate)}`);
            if (request !== state.analyticsRequest) { return; }
            state.analytics = result;
            state.analyticsFilters = {...candidate, ...result.filters, from: result.filters.from || '', to: result.filters.to || '', location_id: result.filters.location_id ? String(result.filters.location_id) : ''};
            if (JSON.stringify(state.analyticsDraft) === JSON.stringify(candidate)) { state.analyticsDraft = {...state.analyticsFilters}; }
        } catch (failure) {
            if (request !== state.analyticsRequest) { return; }
            state.analyticsError = failure.message;
        } finally {
            if (request === state.analyticsRequest) { state.analyticsLoading = false; if (state.section === 'reports' && state.reportView === 'analytics') { renderSection(true); } }
        }
        if (request === state.analyticsRequest && !state.analyticsError) { await loadReceiptHistory(state.receiptHistoryPage); }
    }

    async function loadReceiptHistory(page = 1) {
        if (!state.analytics) { return; }
        const filters = state.analytics.filters;
        const context = analyticsQuery(filters, false);
        if (context !== state.receiptHistoryContext) {
            state.receiptHistoryContext = context; state.receiptHistory = null; page = 1;
        }
        const params = new URLSearchParams(context);
        params.set('page', String(page));
        if (state.receiptHistorySearch) { params.set('receipt', state.receiptHistorySearch); }
        const request = ++state.receiptHistoryRequest;
        state.receiptHistoryPage = page; state.receiptHistoryLoading = true; state.receiptHistoryError = null;
        if (state.section === 'reports' && state.reportView === 'analytics') { renderSection(true); }
        try {
            const result = await api(`/sales?${params}`);
            if (request !== state.receiptHistoryRequest) { return; }
            state.receiptHistory = result;
        } catch (failure) {
            if (request === state.receiptHistoryRequest) { state.receiptHistoryError = failure.message; }
        } finally {
            if (request === state.receiptHistoryRequest) {
                state.receiptHistoryLoading = false;
                if (state.section === 'reports' && state.reportView === 'analytics') { renderSection(true); }
            }
        }
    }

    function receiptHistoryPanel() {
        const result = state.receiptHistory;
        const meta = result?.meta;
        const loading = state.receiptHistoryLoading || state.analyticsLoading;
        const error = state.receiptHistoryError ? `<div class="form-error" role="alert">${escape(state.receiptHistoryError)}${result ? ' The last loaded receipts remain below.' : ''} ${button('Retry receipts', 'receipt-history-retry', 'small')}</div>` : '';
        const search = `<form data-form="receipt-search" class="receipt-search-form">${field('Full receipt number', 'receipt', state.receiptHistoryDraft, 'search', 'maxlength="100"', 'Exact number, within the applied date, location and source filters.')}<div><button type="submit" class="button primary small" ${loading ? 'disabled' : ''}>Search</button>${button('Clear search', 'receipt-history-clear', 'small subtle', '', loading ? 'disabled' : '')}</div></form>`;
        const rows = result?.data || [];
        const body = rows.length ? recentSalesTable(rows, true) : loading ? '<div class="quiet-state" role="status"><span class="loading-line" style="display:block;margin:15px auto"></span><h3>Loading receipts…</h3></div>' : empty('No matching receipts.', 'Check the full receipt number or apply a wider date range, location or source. All time includes older sales.', '', true, 'receipt');
        const paging = meta ? `<div class="receipt-history-paging"><p role="status">${loading ? 'Updating receipts…' : `${count(meta.from || 0)}–${count(meta.to || 0)} of ${count(meta.total)} matching receipts · Page ${count(meta.current_page)} of ${count(meta.last_page)}`}</p><div>${button('Previous', 'receipt-history-page', 'small subtle', '', `data-page="${number(meta.current_page) - 1}" ${loading || number(meta.current_page) <= 1 ? 'disabled' : ''}`)}${button('Next', 'receipt-history-page', 'small subtle', '', `data-page="${number(meta.current_page) + 1}" ${loading || number(meta.current_page) >= number(meta.last_page) ? 'disabled' : ''}`)}</div></div>` : '';
        return `<section class="panel recent-sales analytics-receipts"><div class="panel-header"><div><h2>Matching receipts & returns</h2><p>Browse every matching receipt, including older sales.</p></div></div>${search}${error}${body}${paging}</section>`;
    }

    async function openSaleRecord(id, returning = false) {
        const request = ++state.saleRecordRequest;
        try {
            const sale = await api(`/sales/${number(id)}`);
            if (request !== state.saleRecordRequest) { return; }
            if (returning) { returnModal(sale); } else { receiptModal(sale); }
        } catch (failure) {
            if (request === state.saleRecordRequest) { toast(failure.message, true); }
        }
    }

    async function refreshReports() {
        const filters = {...(state.analyticsFilters || initializeReportFilters())};
        const request = ++state.analyticsRequest;
        state.analyticsLoading = true; state.analyticsReceiptError = null;
        if (state.section === 'reports') { renderSection(true); }
        try { await refresh(false); }
        catch (failure) { if (request === state.analyticsRequest) { state.analyticsReceiptError = failure.message; } }
        if (request !== state.analyticsRequest) { return; }
        await loadAnalytics(filters);
    }

    async function loadFinancial(filters = null, silent = false) {
        if (!canManage()) { return; }
        const candidate = {...(filters || state.financialFilters || initializeFinancialFilters())};
        if ((candidate.from && !candidate.to) || (!candidate.from && candidate.to) || (candidate.from && candidate.from > candidate.to)) {
            state.financialRequest += 1; state.financialLoading = false;
            state.financialError = 'Choose both dates, with the end date on or after the start date.';
            if (state.section === 'reports' && state.reportView === 'financial') { renderSection(true); }
            return;
        }
        const request = ++state.financialRequest;
        state.financialLoading = true; state.financialError = null;
        if (!silent && state.section === 'reports' && state.reportView === 'financial') { renderSection(true); }
        try {
            const result = await api(`/reports/financial?${financialQuery(candidate)}`);
            if (request !== state.financialRequest) { return; }
            state.financial = result;
            state.financialFilters = {...candidate, ...result.filters, from: result.filters.from || '', to: result.filters.to || '', location_id: result.filters.location_id ? String(result.filters.location_id) : ''};
            if (JSON.stringify(state.financialDraft) === JSON.stringify(candidate)) { state.financialDraft = {...state.financialFilters}; }
        } catch (failure) {
            if (request !== state.financialRequest) { return; }
            state.financialError = failure.message;
        } finally {
            if (request === state.financialRequest) {
                state.financialLoading = false;
                if (state.section === 'reports' && state.reportView === 'financial') { renderSection(true); }
            }
        }
    }

    function reportFilterForm(report = 'analytics') {
        const financial = report === 'financial';
        const draft = financial ? initializeFinancialFilters() : initializeReportFilters();
        const custom = draft.preset === 'custom';
        const loading = financial ? state.financialLoading : state.analyticsLoading;
        const fourth = financial ? selectField('Report basis', 'report_basis', Object.entries(financialBases).map(([value, label]) => `<option value="${value}" ${draft.basis === value ? 'selected' : ''}>${escape(label)}</option>`).join('')) : selectField('Rank best sellers by', 'report_rank', `<option value="units" ${draft.rank_by === 'units' ? 'selected' : ''}>Net units sold</option><option value="net_total" ${draft.rank_by === 'net_total' ? 'selected' : ''}>Net recorded sales</option>`);
        return `<section class="panel analytics-filter-panel"><form data-form="${report}-filters" class="analytics-filter-form"><div class="analytics-filters">${selectField('Date range · Manila time', 'report_preset', Object.entries(reportPresets).map(([value, label]) => `<option value="${value}" ${draft.preset === value ? 'selected' : ''}>${label}</option>`).join(''))}${selectField('Report location', 'report_location', `<option value="">All locations</option>${locationOptions(draft.location_id)}`, '', false, false)}${selectField('Sales source', 'report_source', Object.entries(reportSources).map(([value, label]) => `<option value="${value}" ${draft.source === value ? 'selected' : ''}>${label}</option>`).join(''))}${fourth}</div><div class="analytics-custom-dates" ${custom ? '' : 'hidden'}>${field('From', 'report_from', draft.from || '', 'date', `required ${custom ? '' : 'disabled'}`)}${field('Through', 'report_to', draft.to || '', 'date', `required ${custom ? '' : 'disabled'}`)}</div><div class="analytics-filter-footer"><p>Report filters keep checkout’s location and cart unchanged.</p><div>${button('Reset filters', `${report}-reset`, 'subtle small')}<button type="submit" class="button primary" ${loading ? 'disabled' : ''}>${loading ? 'Loading report…' : 'Apply filters'}</button></div></div></form></section>`;
    }

    function analyticsScopeLabel(filters) {
        const period = filters.from && filters.to ? `${reportDayLabel(filters.from)}–${reportDayLabel(filters.to)}` : 'All time';
        return `${period} · ${filters.location_id ? locationName(filters.location_id) : 'All locations'} · ${reportSources[filters.source] || 'All sources'}${filters.scope === 'own_sales' ? ' · Your sales only' : ''}`;
    }

    function peakHours(analytics) {
        const hours = analytics.hourly || [];
        const peaks = analytics.peak_hours || [];
        const maximum = Math.max(0, ...hours.map(hour => number(hour.transactions)));
        if (!maximum) { return empty('No transactions in this range', 'Choose another period, location or source to see transactions by hour.', '', true, 'clock'); }
        const peakLabels = peaks.length === 24 ? 'All 24 hours are tied' : peaks.map(reportHour).join(', ');
        const peakText = `${peaks.length > 1 ? 'Peak hours' : 'Peak hour'}: ${peakLabels} · ${count(maximum)} ${maximum === 1 ? 'transaction' : 'transactions'}${peaks.length > 1 ? ' each' : ''}.`;
        const bars = hours.map((hour, index) => {
            const height = number(hour.transactions) / maximum * 108;
            const peak = peaks.includes(number(hour.hour));
            return `<rect x="${12 + index * 23}" y="${130 - height}" width="15" height="${height}" rx="2" class="analytics-hour-bar ${peak ? 'peak' : ''}"><title>${reportHour(hour.hour)}: ${count(hour.transactions)} transactions, ${money(hour.net_total)} net recorded sales</title></rect>${index % 4 === 0 ? `<text x="${19 + index * 23}" y="150" class="analytics-hour-label" text-anchor="middle">${reportHour(hour.hour)}</text>` : ''}`;
        }).join('');
        return `<div class="analytics-hourly"><p class="analytics-peak-summary">${escape(peakText)}</p><svg viewBox="0 0 565 158" class="analytics-hour-chart" role="img" aria-label="${escape(`Transactions across all 24 hours, Manila time. ${peakText} The table below gives every hour’s values.`)}"><path d="M10 130H561" class="analytics-hour-baseline"/>${bars}</svg><p class="analytics-chart-note">Receipt counts include sales that were later returned. Each bar covers one hour in Manila time.</p></div><details class="analytics-hour-details"><summary>See all 24 hours</summary><div class="table-wrap"><table><caption class="sr-only">Hourly transaction counts and net sales, Manila time</caption><thead><tr><th scope="col">Hour</th><th scope="col">Transactions</th><th scope="col">Net units</th><th scope="col">Returned</th><th scope="col">Net sales</th></tr></thead><tbody>${hours.map(hour => `<tr><th scope="row">${reportHour(hour.hour)}${peaks.includes(number(hour.hour)) ? ' · peak' : ''}</th><td>${count(hour.transactions)}</td><td>${count(hour.net_units)}</td><td>${money(hour.returned_total)}</td><td>${money(hour.net_total)}</td></tr>`).join('')}</tbody></table></div></details>`;
    }

    function bestSellersTable(analytics) {
        const rows = analytics.best_sellers || [];
        if (!rows.length) { return empty('No net units to rank yet.', 'Fully returned SKUs have no retained units. Try another report range or source.', '', true, 'trend'); }
        return `<div class="table-wrap"><table class="analytics-ranking"><caption class="sr-only">Best-selling SKUs ranked by ${analytics.filters.rank_by === 'net_total' ? 'net recorded sales' : 'net units sold'}</caption><thead><tr><th scope="col">Rank</th><th scope="col">Product / SKU</th><th scope="col">Sold</th><th scope="col">Returned</th><th scope="col">Net units</th><th scope="col">Net recorded sales</th></tr></thead><tbody>${rows.map((item, index) => `<tr><td class="analytics-rank">${String(index + 1).padStart(2, '0')}</td><td><div class="table-name">${productById(item.product_id) ? productArt(productById(item.product_id), true) : ''}<div><strong>${escape(item.name)}</strong><small>${escape(item.sku)}</small></div></div></td><td>${count(item.units_sold)}</td><td>${count(item.units_returned)}</td><td><strong>${count(item.net_units)}</strong></td><td><strong>${money(item.net_total)}</strong></td></tr>`).join('')}</tbody></table></div><div class="access-note">Each size or color SKU ranks separately. Labels follow the latest matching receipt. Amounts include the original discount and tax, less recorded returns.</div>`;
    }

    function renderReports() {
        return state.reportView === 'financial' && canManage() ? renderFinancialReports() : renderSalesAnalytics();
    }

    function financialBasisNote(basis) {
        return basis === 'sale_cohort' ? 'Sales follow their original sale dates. Refunds include every recorded return for those receipts, including later returns.' : 'Sales follow their sale dates. Refunds follow the dates they were recorded, including refunds for older sales.';
    }

    function financialSource(source, simulated = false) {
        return `${escape(source === 'channel' || source === 'commerce' ? 'Channel sales' : source === 'pos' ? 'Point of sale' : source || 'Unspecified')}${simulated ? ' <span class="badge amber">Simulated</span>' : ''}`;
    }

    function financialTable(caption, headers, rows, noRecords = 'No records match this selection.') {
        if (!rows.length) { return `<div class="quiet-state">${escape(noRecords)}</div>`; }
        return `<div class="table-wrap"><table class="financial-table"><caption class="sr-only">${escape(caption)}</caption><thead><tr>${headers.map(header => `<th scope="col">${escape(header)}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${row.map((cell, index) => index === 0 ? `<th scope="row">${cell}</th>` : `<td>${cell}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
    }

    function financialDetails(title, subtitle, headers, rows, open = false) {
        return `<details class="panel financial-breakdown" data-financial-breakdown="${escape(title)}" ${open || state.financialOpen.includes(title) ? 'open' : ''}><summary><span><strong>${escape(title)}</strong><small>${escape(subtitle)}</small></span><span class="financial-detail-toggle" aria-hidden="true">${icon('plus')}</span></summary>${financialTable(title, headers, rows)}</details>`;
    }

    function financialCoverage(report) {
        const coverage = report.coverage || {};
        const missingDates = number(coverage.undated_legacy_refunds);
        const dated = number(coverage.legacy_dated_refunds);
        const unreconciled = number(coverage.unreconciled_tender_sales);
        const notes = [];
        if (missingDates) {
            notes.push(`${count(missingDates)} historical ${missingDates === 1 ? 'refund has' : 'refunds have'} no recorded date (${money(coverage.undated_legacy_refund_total)}). ${report.filters.basis === 'sale_cohort' ? 'Included with the original sale dates.' : 'Excluded from date-based refund totals; the amount is shown here for the selected location and source.'}`);
        }
        if (dated) { notes.push(`${count(dated)} historical ${dated === 1 ? 'refund uses' : 'refunds use'} a recorded return timestamp (${money(coverage.legacy_dated_refund_total)}).`); }
        if (unreconciled) { notes.push(`${count(unreconciled)} ${unreconciled === 1 ? 'receipt has' : 'receipts have'} payment records that do not reconcile to the original total. Original totals minus known accepted payments: ${money(coverage.unreconciled_tender_total)}. Only known payments are shown below.`); }
        if (!notes.length) { return ''; }
        return `<section class="panel financial-coverage"><h2>Historical record coverage</h2>${notes.map(note => `<p>${escape(note)}</p>`).join('')}</section>`;
    }

    function renderFinancialReports() {
        const report = state.financial;
        const top = heading('Financial reports', 'Recorded sales, returns, and tax for the selected filters.', button('Refresh report', 'financial-refresh', 'subtle', 'refresh', state.financialLoading ? 'disabled' : '') + (isAdmin() ? button('Export financial CSV', 'export-financial', '', 'download', !report || state.financialLoading ? 'disabled' : '') : ''), '', 'analytics-heading workspace-heading');
        const filters = reportFilterForm('financial');
        const error = state.financialError ? `<div class="form-error" role="alert">${escape(state.financialError)}${report ? ' Your last successful report is still shown below.' : ''} ${button('Retry last report', 'financial-retry', 'small')}</div>` : '';
        if (!report) {
            return reportPage(top, `${filters}${error}${state.financialLoading ? '<div class="panel quiet-state" role="status"><span class="loading-line" style="display:block;margin:15px auto"></span><h3>Loading financial report…</h3><p>Totals use the full matching history.</p></div>' : empty('No financial report loaded', 'Apply filters or retry to load the recorded amounts.', button('Load report', 'financial-refresh', 'primary'), false, 'receipt')}`);
        }
        const summary = report.summary;
        const sales = report.sales;
        const refunds = report.refunds;
        const net = report.net;
        const costs = report.costs;
        const missingUnits = number(costs.missing_sales_units) + number(costs.missing_return_units);
        const marginAvailable = costs.estimated_gross_margin !== null && costs.estimated_gross_margin !== undefined;
        const stats = `${metric('Net sales excluding tax', money(net.revenue_ex_tax), 'Sales less recorded refunds, before tax', 'dollar')}${metric('Net recorded tax', money(net.tax), 'Original tax less returned tax', 'receipt')}${metric('Recorded refunds', money(refunds.total), `${count(summary.refund_count)} ${number(summary.refund_count) === 1 ? 'refund record' : 'refund records'} · including tax`, 'return')}${metric('Total after refunds', money(net.total), `${count(summary.sales_count)} ${number(summary.sales_count) === 1 ? 'sale' : 'sales'} · after discounts, including tax`, 'trend')}`;
        const reconciliationRows = [['Before discounts', 'subtotal'], ['Discounts', 'discount'], ['Sales excluding tax', 'revenue_ex_tax'], ['Tax', 'tax'], ['Total including tax', 'total']].map(([label, key]) => [escape(label), money(sales[key]), money(refunds[key]), `<strong>${money(net[key])}</strong>`]);
        const groupHeaders = ['Group', 'Sales', 'Refunds', 'Original total', 'Refund total', 'Net excluding tax', 'Net tax', 'Net total'];
        const groupRow = (row, label) => [label, count(row.sales_count), count(row.refund_count), money(row.sales_total), money(row.refund_total), money(row.net_revenue_ex_tax), money(row.net_tax), `<strong>${money(row.net_total)}</strong>`];
        const simulated = number(summary.simulated_sales_count) || number(summary.simulated_refund_count);
        const simulation = simulated ? `<div class="sample-banner analytics-simulation">${icon('info')}<span>Includes ${count(summary.simulated_sales_count)} simulated channel ${number(summary.simulated_sales_count) === 1 ? 'sale' : 'sales'} and ${count(summary.simulated_refund_count)} simulated ${number(summary.simulated_refund_count) === 1 ? 'refund' : 'refunds'}. No real channel payment or refund was processed.</span></div>` : '';
        const margin = `<section class="panel financial-costs"><div class="panel-header"><div><h2>Estimated gross margin</h2><p>Sales excluding tax, less recorded item costs.</p></div>${icon('stock')}</div><div class="financial-margin ${marginAvailable ? '' : 'unavailable'}"><strong>${marginAvailable ? money(costs.estimated_gross_margin) : 'Unavailable'}</strong><span>${marginAvailable ? 'Excludes expenses and payment settlement.' : missingUnits ? 'Some included units lack original cost snapshots.' : 'No margin estimate is available for this selection.'}</span></div><div class="financial-cost-lines"><div class="receipt-line"><span>Cost of sales · known snapshots</span><strong>${money(costs.sales_cost)}</strong></div><div class="receipt-line"><span>Returned cost · known snapshots</span><strong>${number(costs.returned_cost) > 0 ? '−' : ''}${money(costs.returned_cost)}</strong></div><div class="receipt-line total"><span>Net known item cost</span><strong>${money(costs.net_cost)}</strong></div></div><div class="financial-cost-coverage"><p>${count(costs.snapshot_sales_units)} sold units and ${count(costs.snapshot_return_units)} returned units have original cost snapshots.</p>${missingUnits ? `<p class="financial-missing-cost">Missing costs: ${count(costs.missing_sales_units)} sold units · ${count(costs.missing_return_units)} returned units. Margin stays unavailable; current catalog costs are never substituted.</p>` : '<p>Costs come from the original receipt snapshots.</p>'}</div></section>`;
        const tenders = (report.tenders || []).map(row => [escape({cash: 'Cash', card: 'Card', wallet: 'E-wallet'}[row.method] || row.method || 'Unspecified'), financialSource(row.source, row.simulated), count(row.transactions), money(row.tendered), money(row.change), `<strong>${money(row.accepted)}</strong>`]);
        const taxRows = (report.tax_rates || []).map(row => [`${escape(String(row.tax_percent))}%`, money(row.sales_revenue_ex_tax), money(row.refund_revenue_ex_tax), money(row.net_revenue_ex_tax), money(row.sales_tax), money(row.refund_tax), `<strong>${money(row.net_tax)}</strong>`]);
        const breakdowns = financialDetails('Daily movement', report.filters.basis === 'sale_cohort' ? 'Original sale dates · later refunds stay with those sales.' : 'Sale dates and recorded refund dates · Manila time.', ['Date', ...groupHeaders.slice(1)], (report.daily || []).map(row => groupRow(row, escape(reportDayLabel(row.date))))) + financialDetails('By location', 'Each location uses the same selected basis.', ['Location', ...groupHeaders.slice(1)], (report.locations || []).map(row => groupRow(row, escape(row.name)))) + financialDetails('By sales source', 'Simulated channel records are identified separately.', ['Source', ...groupHeaders.slice(1)], (report.sources || []).map(row => groupRow(row, financialSource(row.source, row.simulated)))) + financialDetails('Tax breakdown', 'Original receipt tax rates, with returned amounts deducted.', ['Tax rate', 'Sales excluding tax', 'Refunds excluding tax', 'Net excluding tax', 'Sales tax', 'Returned tax', 'Net tax'], taxRows) + financialDetails('Original payment records', 'Tender amounts less cash change. Refund methods are not recorded.', ['Method', 'Source', 'Transactions', 'Tendered', 'Cash change', 'Accepted'], tenders);
        const emptyNotice = !number(summary.sales_count) && !number(summary.refund_count) ? '<div class="panel quiet-state financial-empty"><h3>No dated records in this selection.</h3><p>Choose another range, location or source. Historical coverage notes can still identify undated refunds.</p></div>' : '';
        return reportPage(top, `${filters}${error}<div class="analytics-scope"><span>${escape(analyticsScopeLabel(report.filters))} · ${escape(financialBases[report.filters.basis] || financialBases.activity)}</span><small>${state.financialLoading ? 'Refreshing selected filters… Last results remain visible.' : `Full matching history · updated ${time(report.generated_at)}`}</small></div>${simulation}<div class="stats-grid analytics-stats">${stats}</div><div class="analytics-definition">${escape(financialBasisNote(report.filters.basis))} Amounts reflect recorded activity; this report does not establish settlement or profit.</div>${emptyNotice}<div class="financial-summary-grid"><section class="panel financial-reconciliation"><div class="panel-header"><div><h2>From original sale to recorded net</h2><p>Refund columns are deducted from the original amounts.</p></div>${icon('receipt')}</div>${financialTable('Sales, refunds and net amounts', ['Amount', 'Original sales', 'Refunds', 'Net'], reconciliationRows)}<p class="financial-table-note">Total = subtotal − discounts + tax. All amounts are in Philippine pesos.</p></section>${margin}</div>${financialCoverage(report)}<div class="financial-breakdowns">${breakdowns}</div><div class="financial-tender-note">${icon('info')}<p>Refunds are unallocated by payment method: ${money(report.coverage?.unallocated_refund_total)}. Accepted amounts describe original payment records only; they are not provider settlement balances.</p></div>${help('Choose Sales & refund dates for activity in the period, or Original sale dates to follow a receipt group and all its returns. Export uses the last successfully applied filters.', 'financial')}`);
    }

    function renderSalesAnalytics() {
        const analytics = state.analytics;
        const top = heading('Sales analytics', 'Sales patterns and product rankings from all matching receipts.', button('Refresh report', 'analytics-refresh', 'subtle', 'refresh', state.analyticsLoading ? 'disabled' : '') + (isAdmin() ? button('Export sales CSV', 'export-sales', '', 'download', !analytics || state.analyticsLoading ? 'disabled' : '') : ''), '', 'analytics-heading workspace-heading');
        const error = (state.analyticsError ? `<div class="form-error" role="alert">${escape(state.analyticsError)}${analytics ? ' Your last successful report is still shown below.' : ''} ${button('Retry last report', 'analytics-retry', 'small')}</div>` : '') + (state.analyticsReceiptError ? `<div class="form-error" role="alert">Workspace summaries could not refresh. ${escape(state.analyticsReceiptError)} Use Refresh report to try again.</div>` : '');
        const filters = reportFilterForm();
        if (!analytics) { return reportPage(top, `${filters}${error}${state.analyticsLoading ? '<div class="panel quiet-state" role="status"><span class="loading-line" style="display:block;margin:15px auto"></span><h3>Loading sales analytics…</h3><p>Totals and rankings include every matching receipt.</p></div>' : empty('No sales report loaded', 'Apply filters or retry to load your recorded sales.', button('Load report', 'analytics-refresh', 'primary'), false, 'trend')}`); }
        const summary = analytics.summary;
        const stats = `${metric('Net recorded sales', money(summary.net_total), 'After discounts and returns, including tax', 'dollar')}${metric('Recorded transactions', count(summary.transactions), `${money(summary.average_basket)} average net basket`, 'receipt')}${metric('Net units sold', count(summary.net_units), `${count(summary.units_sold)} sold · ${count(summary.units_returned)} returned`, 'cart')}${metric('Recorded returns', money(summary.returned_total), `${count(summary.returned_transactions)} ${number(summary.returned_transactions) === 1 ? 'receipt' : 'receipts'} with returns`, 'return')}`;
        return reportPage(top, `${filters}${error}<div class="analytics-scope"><span>${escape(analyticsScopeLabel(analytics.filters))}</span><small>${state.analyticsLoading ? 'Refreshing selected filters… Last results remain visible.' : `Full matching history · updated ${time(analytics.generated_at)}`}</small></div>${number(summary.simulated_transactions) ? `<div class="sample-banner analytics-simulation">${icon('info')}<span>Includes ${count(summary.simulated_transactions)} simulated channel ${number(summary.simulated_transactions) === 1 ? 'sale' : 'sales'}. No real payment was processed for these imports.</span></div>` : ''}<div class="stats-grid analytics-stats">${stats}</div><div class="analytics-definition">Traffic includes returned receipts. Net figures reflect all recorded returns for sales made during this range, including returns recorded later.</div><div class="analytics-panels"><section class="panel"><div class="panel-header"><div><h2>Busy hours</h2><p>Transaction counts across all 24 hours · Manila time.</p></div>${icon('clock')}</div>${peakHours(analytics)}</section><section class="panel analytics-totals"><div class="panel-header"><div><h2>From sale to net total</h2><p>Recorded amounts for this selection.</p></div>${icon('receipt')}</div><div><div class="receipt-line"><span>Original sales total</span><strong>${money(summary.gross_total)}</strong></div><div class="receipt-line"><span>Recorded returns</span><strong>−${money(summary.returned_total)}</strong></div><div class="receipt-line total"><span>Net recorded sales</span><strong>${money(summary.net_total)}</strong></div></div><p>Original totals include discounts and tax. These records do not represent payment settlement or profit.</p></section></div><section class="panel analytics-bestsellers"><div class="panel-header"><div><h2>Best-selling products by SKU</h2><p>Ranked by ${analytics.filters.rank_by === 'net_total' ? 'net recorded sales' : 'net units sold'}.</p></div><span class="period-label">${number(analytics.ranked_product_count ?? analytics.best_sellers.length) > 10 ? `Top 10 of ${count(analytics.ranked_product_count)} SKUs` : `${count(analytics.ranked_product_count ?? analytics.best_sellers.length)} ${number(analytics.ranked_product_count ?? analytics.best_sellers.length) === 1 ? 'SKU' : 'SKUs'} ranked`}</span></div>${bestSellersTable(analytics)}</section>${receiptHistoryPanel()}${help(canManage() ? 'Choose a receipt to view it or record a return. Original prices, discounts and taxes determine refunds. Process any real refund through the original provider separately.' : 'Reports include only sales made by your account. Ask a manager to process returns.', 'reports')}`);
    }

    async function loadMonitoring(silent = false) {
        if (!isAdmin()) { return; }
        const request = ++state.monitoringRequest;
        state.monitoringLoading = true; state.monitoringError = null;
        if (!silent && state.section === 'monitoring') { renderSection(true); }
        try {
            const result = await api('/monitoring');
            if (request !== state.monitoringRequest) { return; }
            state.monitoring = result;
            state.monitoringCooldownUntil = Date.now() + number(result.queue?.cooldown_remaining_seconds) * 1000;
            if (state.monitoringProbeUncertain) { state.monitoringActionError = null; }
            state.monitoringProbeUncertain = false;
        } catch (failure) {
            if (request !== state.monitoringRequest) { return; }
            state.monitoringError = failure.message;
        } finally {
            if (request === state.monitoringRequest) {
                state.monitoringLoading = false;
                if (state.section === 'monitoring') { renderSection(true); }
            }
        }
    }

    const monitoringLabels = {healthy: 'Healthy', warning: 'Needs review', critical: 'Unavailable', unverified: 'Not verified', simulation: 'Simulated', test: 'Test mode', live: 'Live mode', disabled: 'Not enabled', inline: 'Inline', background: 'Background', pending: 'Pending', passed: 'Passed', failed: 'Failed'};
    const monitoringBadge = status => statusBadge(monitoringLabels[status] || 'Unknown', ['healthy', 'passed', 'live'].includes(status) ? 'green' : ['critical', 'failed'].includes(status) ? 'red' : ['warning', 'pending', 'simulation', 'test', 'unverified', 'inline'].includes(status) ? 'amber' : '');

    function monitoringAge(seconds) {
        if (seconds === null || seconds === undefined) { return ''; }
        const minutes = Math.floor(Math.max(0, number(seconds)) / 60);
        if (!minutes) { return 'less than a minute ago'; }
        if (minutes < 60) { return `${count(minutes)} ${minutes === 1 ? 'minute' : 'minutes'} ago`; }
        const hours = Math.floor(minutes / 60);
        if (hours < 24) { return `${count(hours)} ${hours === 1 ? 'hour' : 'hours'} ago`; }
        const days = Math.floor(hours / 24);
        return `${count(days)} ${days === 1 ? 'day' : 'days'} ago`;
    }

    function monitoringProbeState() {
        const cooldown = Math.max(0, Math.ceil((state.monitoringCooldownUntil - Date.now()) / 1000));
        const pending = number(state.monitoring?.counts?.pending_probes);
        const disabled = !state.monitoring || state.monitoringBusy || state.monitoringLoading || (!state.monitoringProbeUncertain && (pending > 0 || cooldown > 0 || state.monitoring.queue?.mode === 'disabled'));
        const note = state.monitoringProbeUncertain ? 'The last request could not be confirmed. Check the diagnostic history before starting another check.' : pending ? 'A background check is still pending. Refresh health to see its result; repeated requests reuse the pending check.' : state.monitoring?.queue?.mode === 'disabled' ? 'Background diagnostics are not available for this queue configuration. Review the queue check below.' : cooldown ? `Please wait ${count(cooldown)} ${cooldown === 1 ? 'second' : 'seconds'} before running another diagnostic.` : state.monitoring?.queue?.mode === 'inline' ? 'Inline queue mode: the diagnostic runs during this request. A passed result does not verify a separate background worker.' : 'The diagnostic queues a harmless check. It does not change sales, stock or the current cart.';
        return {disabled, note, label: state.monitoringBusy ? 'Running check…' : state.monitoringProbeUncertain ? 'Check diagnostic status' : 'Run background check'};
    }

    function updateMonitoringControls() {
        if (state.section !== 'monitoring') { return; }
        const control = document.getElementById('monitoring-probe-button');
        if (!control) { return; }
        const probe = monitoringProbeState();
        if (control.disabled !== probe.disabled) { control.disabled = probe.disabled; }
        if (control.dataset.label !== probe.label) {
            control.innerHTML = `${escape(probe.label)} ${icon('health')}`;
            control.dataset.label = probe.label;
        }
        const note = document.getElementById('monitoring-probe-note');
        if (note && note.textContent !== probe.note) { note.textContent = probe.note; }
    }

    async function runMonitoringProbe() {
        if (!isAdmin() || state.monitoringBusy || state.monitoringLoading) { return; }
        if (state.monitoringProbeUncertain) { await loadMonitoring(); return; }
        if (monitoringProbeState().disabled) { return; }
        state.monitoringBusy = true; state.monitoringActionError = null;
        state.monitoringRequest += 1;
        if (state.section === 'monitoring') { renderSection(true); }
        try {
            const result = await api('/monitoring/probe', 'POST', {});
            state.monitoringCooldownUntil = Date.now() + number(result.cooldown_remaining_seconds) * 1000;
            if (result.probe) {
                state.monitoring.probes = [result.probe, ...(state.monitoring.probes || []).filter(probe => number(probe.id) !== number(result.probe.id))].slice(0, number(state.monitoring.history_limit || 50));
                state.monitoring.counts.pending_probes = result.probe.status === 'pending' ? 1 : 0;
            }
            toast(result.reused ? 'The pending diagnostic was reused. Its result will appear in the history.' : result.probe?.status === 'passed' ? 'The diagnostic passed. Review the queue mode to see how it ran.' : result.probe?.status === 'failed' ? 'The diagnostic recorded a failure. Review its history.' : 'Background check queued. Refresh health to see its result.', result.probe?.status === 'failed');
            await loadMonitoring();
        } catch (failure) {
            const uncertain = !failure.status || failure.status >= 500;
            state.monitoringProbeUncertain = uncertain;
            state.monitoringActionError = uncertain ? 'The diagnostic request could not be confirmed. Use Check diagnostic status to read the latest history before running another check.' : failure.message;
            if (!uncertain) { await loadMonitoring(); }
        } finally {
            state.monitoringBusy = false;
            if (state.section === 'monitoring') { renderSection(true); }
        }
    }

    async function acknowledgeMonitoringIncident(id) {
        if (!isAdmin() || state.monitoringBusy || state.monitoringLoading) { return; }
        const incident = state.monitoring?.incidents?.find(item => number(item.id) === number(id));
        if (!incident || incident.acknowledged_at) { return; }
        state.monitoringBusy = true; state.monitoringActionError = null;
        state.monitoringRequest += 1;
        if (state.section === 'monitoring') { renderSection(true); }
        try {
            const result = await api(`/monitoring/incidents/${number(id)}/acknowledge`, 'POST', {});
            state.monitoring.incidents = state.monitoring.incidents.map(item => number(item.id) === number(id) ? result.incident : item);
            state.monitoring.counts = {...state.monitoring.counts, open_incidents: Math.max(0, number(state.monitoring.counts?.open_incidents) - 1), acknowledged_incidents: number(state.monitoring.counts?.acknowledged_incidents) + 1};
            toast('Incident acknowledged. The recorded failure and diagnostic history are kept.');
            await loadMonitoring();
        } catch (failure) { state.monitoringActionError = failure.message; }
        finally { state.monitoringBusy = false; if (state.section === 'monitoring') { renderSection(true); } }
    }

    function renderMonitoring() {
        const health = state.monitoring;
        const probe = monitoringProbeState();
        const top = heading('System health', sections.monitoring[1], button('Refresh health', 'monitoring-refresh', 'subtle', 'refresh', state.monitoringLoading || state.monitoringBusy ? 'disabled' : '') + button(probe.label, 'monitoring-probe', 'primary', 'health', `id="monitoring-probe-button" data-label="${escape(probe.label)}" ${probe.disabled ? 'disabled' : ''}`), '', 'analytics-heading workspace-heading');
        const error = state.monitoringError ? `<div class="form-error" role="alert">${escape(state.monitoringError)}${health ? ' Your last successful checks remain visible.' : ''} ${button('Try again', 'monitoring-refresh', 'small', '', state.monitoringLoading || state.monitoringBusy ? 'disabled' : '')}</div>` : '';
        const actionError = state.monitoringActionError ? `<div class="form-error monitoring-action-error" role="alert">${escape(state.monitoringActionError)}</div>` : '';
        if (!health) { return `${top}${error}${actionError}<section class="panel quiet-state">${state.monitoringLoading ? '<span class="loading-line" style="display:block;margin:15px auto"></span><h3>Loading system checks…</h3><p>Getting local diagnostics and this business’s recent history.</p>' : '<h3>No health report loaded</h3><p>Refresh health to read the local checks.</p>'}</section>${help('System health is available to administrators. Safe diagnostics keep the current cart and location unchanged.', 'monitoring')}`; }
        const checks = health.checks || [];
        const status = health.status || 'unverified';
        const overall = {healthy: 'Local checks look healthy.', warning: 'A few checks need review.', critical: 'A service needs attention.'}[status] || 'Some checks are not verified.';
        const checkCards = checks.map(check => {
            const heartbeat = check.stale_after_seconds !== null && check.stale_after_seconds !== undefined;
            const seen = check.last_seen_at ? `${time(check.last_seen_at)}${check.age_seconds !== null && check.age_seconds !== undefined ? ` · ${monitoringAge(check.age_seconds)}` : ''}` : heartbeat ? 'No heartbeat observed yet.' : '';
            return `<article class="monitoring-check"><div class="monitoring-check-top"><span class="monitoring-check-icon">${icon(heartbeat ? 'clock' : check.key === 'database' ? 'stock' : 'health')}</span>${monitoringBadge(check.status)}</div><h3>${escape(check.label)}</h3><p class="monitoring-check-message">${escape(check.summary)}</p>${seen ? `<div class="monitoring-check-pulse">${icon('clock')}<span>${heartbeat ? 'Last heartbeat: ' : 'Last observed: '}${escape(seen)}</span></div>` : ''}${check.next_step ? `<div class="monitoring-check-step"><strong>Next step</strong>${escape(check.next_step)}</div>` : ''}</article>`;
        }).join('');
        const probes = health.probes || [];
        const incidents = health.incidents || [];
        const historyNote = `Latest ${count(health.history_limit || 50)} records · last ${count(health.retention_days || 30)} days · this business only.`;
        const probeHistoryNote = `Up to ${count(health.history_limit || 50)} checks · completed history kept for ${count(health.retention_days || 30)} days. Pending checks remain visible until completion.`;
        const probeHistory = probes.length ? `<div class="monitoring-record-list">${probes.map(record => `<article class="monitoring-record"><div class="monitoring-record-top"><div><strong>Background check #${number(record.id)}</strong><small>Requested ${time(record.created_at)}${record.completed_at ? ` · finished ${time(record.completed_at)}` : ''}</small></div>${monitoringBadge(record.status)}</div><p>${escape(record.summary)}</p>${record.stale ? '<p class="monitoring-stale">Still pending beyond the expected time. It remains pending until a worker processes it.</p>' : ''}</article>`).join('')}</div>` : empty('No diagnostic history yet.', 'Run background check to test how this queue processes a harmless job.', '', true, 'health');
        const incidentHistory = incidents.length ? `<div class="monitoring-record-list">${incidents.map(incident => `<article class="monitoring-record"><div class="monitoring-record-top"><div><strong>${incident.kind === 'queue_failure' ? 'Background job failure' : 'Application error'} #${number(incident.id)}</strong><small>${time(incident.created_at)}</small></div>${incident.acknowledged_at ? statusBadge('Acknowledged') : monitoringBadge(incident.status)}</div><p>${escape(incident.summary)}</p><div class="monitoring-record-actions">${incident.acknowledged_at ? `<small>Acknowledged ${time(incident.acknowledged_at)} · failure record retained.</small>` : button('Acknowledge incident', 'monitoring-acknowledge', 'small', 'check', `data-id="${number(incident.id)}" ${state.monitoringBusy || state.monitoringLoading ? 'disabled' : ''}`)}</div></article>`).join('')}</div>` : empty('No retained incidents for this business.', 'New application errors and failed background jobs appear here when they are recorded.', '', true, 'check');
        const integrations = health.integrations || [];
        return `${top}<div class="monitoring-page">${error}${actionError}<p class="monitoring-action-note" id="monitoring-probe-note" aria-live="off">${escape(probe.note)}</p><section class="panel monitoring-summary"><div class="monitoring-summary-main"><span class="monitoring-summary-icon ${escape(status)}">${icon(status === 'healthy' ? 'check' : 'alert')}</span><div><h2>${overall}</h2><p>${count(checks.filter(check => check.status === 'healthy').length)} of ${count(checks.length)} checks healthy · ${count(health.counts?.open_incidents)} unacknowledged incidents.</p></div></div><div class="monitoring-summary-meta">${state.monitoringLoading ? 'Refreshing checks… Last results remain visible.' : 'Last checked · Manila time'}<strong>${time(health.checked_at)}</strong>${status === 'critical' ? statusBadge('Needs attention', 'red') : monitoringBadge(status)}</div></section><div class="sample-banner monitoring-notice">${icon('info')}<span>Service checks and worker/scheduler heartbeats describe this shared application runtime; history below belongs only to this business. A recent heartbeat verifies recent activity, without guaranteeing future availability.</span></div>${checks.length ? `<div class="monitoring-checks">${checkCards}</div>` : '<section class="panel quiet-state"><h3>No individual checks were returned.</h3><p>Refresh health to load the available service checks. Recent history remains below.</p></section>'}<section class="panel monitoring-runtime"><div class="panel-header"><div><h2>Integration configuration</h2><p>Payment modes show configuration; they do not verify provider availability.</p></div>${icon('info')}</div>${integrations.length ? `<div class="monitoring-config-grid">${integrations.map(integration => `<article class="monitoring-config">${monitoringBadge(integration.status)}<h3>${escape(integration.label)}</h3><p>${escape(integration.summary)}</p></article>`).join('')}</div>` : '<div class="quiet-state">No integration configuration was returned.</div>'}</section><div class="monitoring-histories"><section class="panel"><div class="panel-header"><div><h2>Background check history</h2><p>${probeHistoryNote}</p></div>${monitoringBadge(health.queue?.mode)}</div>${probeHistory}</section><section class="panel"><div class="panel-header"><div><h2>Incident history</h2><p>${historyNote}</p></div><span class="period-label">${count(health.counts?.open_incidents)} open</span></div>${incidentHistory}</section></div>${help('Run a harmless diagnostic, review the recorded result, and follow any next steps on the check cards. Acknowledgment records that an incident was seen; it does not repair the failure.', 'monitoring')}</div>`;
    }

    function renderTeam() {
        const roles = {admin: 'Full workspace access, team permissions and settings.', manager: 'Sales, products, stock transfers, purchasing and reports.', cashier: 'Checkout, customer tagging and their own sales history.'};
        return `${heading('Team & access', sections.team[1], button('Add team member', 'add-team', 'primary', 'plus'), '', 'workspace-heading')}<section class="panel"><div class="table-wrap"><table><thead><tr><th>Team member</th><th>Role</th><th>Access</th><th>Status</th><th>Actions</th></tr></thead><tbody>${(state.data.team || []).map(member => `<tr><td><div class="table-name"><span class="team-avatar">${escape(initials(member.name))}</span><div><strong>${escape(member.name)} ${number(member.id) === number(state.data.user.id) ? '<span class="badge">You</span>' : ''}</strong><small>${escape(member.email)}</small></div></div></td><td>${statusBadge(member.role, member.role === 'admin' ? 'red' : '')}</td><td class="role-description">${roles[member.role] || 'Workspace member'}</td><td>${statusBadge(member.is_active === false ? 'Paused' : 'Active', member.is_active === false ? 'amber' : 'green')}</td><td><div class="table-actions"><button data-action="edit-team" data-id="${member.id}">Edit access</button>${number(member.id) !== number(state.data.user.id) ? `<button class="${member.is_active === false ? '' : 'danger'}" data-action="toggle-team" data-id="${member.id}">${member.is_active === false ? 'Reactivate' : 'Pause access'}</button>` : ''}</div></td></tr>`).join('')}</tbody></table></div><div class="access-note">Pausing access stops workspace use and keeps the member’s sales records. Keep at least one active administrator. Another administrator must change your own role or pause your access.</div></section>${help('Add a team member with an initial password, then securely share their login details yourself. No invitation email is sent.', 'team')}`;
    }

    const activityLabels = {
        'checkout.confirmed': 'Checkout confirmed', 'return.confirmed': 'Return confirmed',
        'product.created': 'Product added', 'product.updated': 'Product edited',
        'product_family.created': 'Variant family created', 'product_family.variants_added': 'Variants added',
        'inventory.adjusted': 'Stock adjusted', 'inventory.transferred': 'Stock transferred',
        'purchase.created': 'Purchase order created', 'delivery.confirmed': 'Delivery receipt confirmed',
        'customer.created': 'Customer added', 'supplier.created': 'Supplier added',
        'location.created': 'Location added', 'member.created': 'Team member added',
        'member.updated': 'Team access edited', 'settings.updated': 'Settings updated',
        'purchase.approved': 'Purchase order approved', 'purchase.canceled': 'Outstanding order canceled',
        'replenishment.configured': 'Replenishment rule updated', 'replenishment.generated': 'Restock drafts generated',
        'subscription.plan_changed': 'Plan selection updated', 'subscription.invoice_created': 'Local invoice created',
        'subscription.canceled': 'Renewal canceled', 'subscription.resumed': 'Renewal resumed',
        'subscription.payment_simulated': 'Invoice payment simulated',
        'subscription.test_checkout_requested': 'PayMongo checkout requested', 'subscription.test_payment_checked': 'PayMongo payment checked',
        'subscription.test_checkout_recovered': 'PayMongo checkout recovered',
        'subscription.checkout_requested': 'PayMongo checkout requested', 'subscription.payment_checked': 'PayMongo payment checked',
        'subscription.checkout_recovered': 'PayMongo checkout recovered',
        'register.opened': 'Cash session opened', 'register.cash_recorded': 'Cash drawer movement recorded',
        'register.closed': 'Cash session closed',
        'channel.created': 'Channel added', 'channel.updated': 'Channel settings edited',
        'channel.paused': 'Channel paused', 'channel.resumed': 'Channel resumed',
        'channel.sync_recorded': 'Channel sync attempt recorded', 'channel.order_reserved': 'Channel order reserved',
        'channel.order_imported': 'Channel order imported', 'channel.order_canceled': 'Pending channel order canceled',
    };

    function renderActivity() {
        const filters = state.activityFilters;
        const actors = state.activity?.actors || state.data.team || [];
        const records = state.activity?.data || [];
        const meta = state.activity?.meta || {current_page: 1, last_page: 1, per_page: 25, total: 0};
        const start = meta.total ? (meta.current_page - 1) * meta.per_page + 1 : 0;
        const end = Math.min(meta.current_page * meta.per_page, meta.total);
        return `${heading('Activity log', sections.activity[1], button('Refresh activity', 'refresh-activity', '', 'refresh'), '', 'workspace-heading')}<div class="sample-banner">${icon('info')}<span>All locations in this business. Successful actions are logged from the time this feature was enabled. Repeated checkout, return or delivery confirmations can appear more than once.</span></div><section class="panel activity-filter-panel"><form data-form="activity-filters" class="activity-filter-form"><div class="activity-filters">${selectField('Team member', 'actor_id', `<option value="">All team members</option>${actors.map(actor => `<option value="${number(actor.id)}" ${String(filters.actor_id) === String(actor.id) ? 'selected' : ''}>${escape(actor.name)}</option>`).join('')}`, '', false, false)}${selectField('Action', 'action', `<option value="">All actions</option>${Object.entries(activityLabels).map(([value, label]) => `<option value="${value}" ${filters.action === value ? 'selected' : ''}>${label}</option>`).join('')}`, '', false, false)}${field('From date', 'from', filters.from, 'date')}${field('To date', 'to', filters.to, 'date')}</div><div class="activity-filter-actions"><button class="button primary" type="submit" ${state.activityLoading ? 'disabled' : ''}>Apply filters</button>${button('Reset', 'reset-activity', 'subtle')}</div></form></section>${state.activityError ? `<div class="form-error" role="alert">${escape(state.activityError)}</div>` : ''}<section class="panel">${state.activityLoading ? '<div class="quiet-state"><span class="loading-line" style="display:block;margin:15px auto"></span><h3>Loading the activity record…</h3><p>Getting the latest successful workspace actions.</p></div>' : records.length ? `<div class="table-wrap"><table><thead><tr><th>When · Manila time</th><th>Team member</th><th>Action</th><th>Record</th></tr></thead><tbody>${records.map(entry => `<tr><td>${time(entry.created_at)}</td><td><div class="table-name"><span class="team-avatar">${escape(initials(entry.actor_name))}</span><strong>${escape(entry.actor_name)}</strong></div></td><td>${escape(activityLabels[entry.action] || entry.action)}</td><td><span class="badge">${escape(String(entry.resource_type).replace(/_/g, ' '))}${entry.resource_id ? ` #${number(entry.resource_id)}` : ''}</span></td></tr>`).join('')}</tbody></table></div>` : empty('No matching activity', state.activityError ? 'Fix the connection or filters and refresh to load the activity record.' : 'No successful actions match these filters yet. New sales and workspace edits will appear here.', '', true, 'receipt')}<div class="activity-pagination"><span>${state.activityLoading ? 'Loading records…' : `${start}–${end} of ${count(meta.total)} actions`}</span><div>${button('← Previous', 'activity-page', 'small', '', `data-page="${meta.current_page - 1}" ${state.activityLoading || meta.current_page <= 1 ? 'disabled' : ''}`)}<span>Page ${meta.current_page} of ${meta.last_page}</span>${button('Next →', 'activity-page', 'small', '', `data-page="${meta.current_page + 1}" ${state.activityLoading || meta.current_page >= meta.last_page ? 'disabled' : ''}`)}</div></div></section>${help('This log keeps who acted, the action, the record reference and the time. Passwords, payment details and request contents are not stored in the activity log.', 'activity')}`;
    }

    async function loadActivity(page = state.activityPage) {
        if (!isAdmin() || state.activityLoading) { return; }
        state.activityLoading = true;
        state.activityError = null;
        state.activityPage = Math.max(1, number(page));
        if (state.section === 'activity') { renderSection(); }
        const query = new URLSearchParams({page: state.activityPage, per_page: 25});
        Object.entries(state.activityFilters).forEach(([name, value]) => { if (value) { query.set(name, value); } });
        try { state.activity = await api(`/activity?${query.toString()}`); }
        catch (failure) { state.activityError = failure.message; }
        finally { state.activityLoading = false; if (state.section === 'activity') { renderSection(); } }
    }

    function receiptBrandingHtml(settings = state.data.settings || {}, logoUrl = settings.receipt_logo_url) {
        return `${logoUrl ? `<img class="receipt-logo" src="${escape(logoUrl)}" alt="${escape(state.data.tenant.name)} logo">` : ''}<h3>${escape(state.data.tenant.name)}</h3>${settings.receipt_address ? `<div class="receipt-business-detail receipt-address">${escape(settings.receipt_address)}</div>` : ''}${settings.receipt_phone ? `<div class="receipt-business-detail">Contact: ${escape(settings.receipt_phone)}</div>` : ''}`;
    }

    function clearReceiptLogoPreview() {
        if (state.receiptLogoPreviewUrl) { URL.revokeObjectURL(state.receiptLogoPreviewUrl); state.receiptLogoPreviewUrl = null; }
    }

    function updateReceiptSettingsPreview(form, changed) {
        state.settingsDirty = true;
        const fileInput = form.elements.receipt_logo;
        const remove = form.elements.remove_receipt_logo;
        if (changed.name === 'receipt_logo') {
            clearReceiptLogoPreview();
            fileInput.setCustomValidity('');
            const file = fileInput.files[0];
            if (file) {
                remove.checked = false;
                if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 1024 * 1024) {
                    fileInput.setCustomValidity('Choose a PNG, JPEG or WebP image up to 1 MB.');
                    fileInput.reportValidity();
                } else { state.receiptLogoPreviewUrl = URL.createObjectURL(file); }
            }
        }
        if (changed.name === 'remove_receipt_logo' && remove.checked) {
            clearReceiptLogoPreview(); fileInput.value = ''; fileInput.setCustomValidity('');
        }
        const settings = {...state.data.settings, receipt_address: form.elements.receipt_address.value, receipt_phone: form.elements.receipt_phone.value};
        const logoUrl = remove.checked ? null : state.receiptLogoPreviewUrl || settings.receipt_logo_url;
        view.querySelector('#receipt-settings-preview').innerHTML = `${receiptBrandingHtml(settings, logoUrl)}<p>${escape(form.elements.receipt_footer.value)}<br>Payment record · not a fiscal invoice.</p>`;
        view.querySelector('#receipt-preview-status').textContent = 'Unsaved changes · save settings to update receipts.';
    }

    function renderSettings() {
        const settings = state.data.settings || {};
        const branding = `<div class="form-grid">${field('Registered company name', 'receipt_company_name', state.data.tenant.name, 'text', 'readonly', 'Uses the company name registered for this workspace.', true)}<div class="field full"><label for="receipt-address">Company address</label><textarea id="receipt-address" name="receipt_address" maxlength="300" rows="3" placeholder="Street, city, province and postal code">${escape(settings.receipt_address || '')}</textarea><small>Optional. Printed below your company name.</small></div>${field('Contact number', 'receipt_phone', settings.receipt_phone || '', 'tel', 'maxlength="50" autocomplete="tel"', 'Optional. Include an area or country code.', true)}<div class="field full"><label for="receipt-logo">Company logo</label><input id="receipt-logo" name="receipt_logo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="receipt-logo-help"><small id="receipt-logo-help">PNG, JPEG or WebP · up to 1 MB · up to 2,000 × 2,000 pixels.</small><label class="receipt-remove-logo" ${settings.receipt_logo_url ? '' : 'hidden'}><input type="checkbox" name="remove_receipt_logo"><span>Remove current logo when saving</span></label></div></div>`;
        const defaults = `<div class="form-grid">${field('Sales tax (%)', 'tax_percent', settings.tax_percent, 'number', 'required min="0" max="100" step="0.01"', 'Added after any cart discount.')}${field('Currency', 'currency', 'PHP · Philippine peso', 'text', 'disabled', 'All prices and receipts use PHP.')}<div class="field full"><label for="receipt-footer">Receipt message</label><textarea id="receipt-footer" name="receipt_footer" maxlength="200">${escape(settings.receipt_footer ?? 'Thank you for shopping with us.')}</textarea><small>A short note printed at the end of every receipt.</small></div><label class="toggle-field full"><span><strong>Low-stock alerts</strong><p>Flag products when available stock reaches their reorder level.</p></span><input type="checkbox" name="low_stock_alerts" ${settings.low_stock_alerts ? 'checked' : ''}></label></div>`;
        return `${heading('Settings', sections.settings[1], '', '', 'workspace-heading')}<div class="settings-grid"><section class="panel"><div class="panel-header"><div><h2>Company & receipt details</h2><p>Company details used on receipts.</p></div></div><form class="settings-form" data-form="settings" enctype="multipart/form-data"><div class="form-error" hidden role="alert"></div>${branding}<div class="settings-section-heading"><h2>Checkout & inventory</h2><p>Default checkout and inventory settings.</p></div>${defaults}<div class="form-actions"><button class="button primary" type="submit">Save settings ${icon('check')}</button></div></form></section><div class="settings-sidebar"><section class="panel receipt-preview-panel" aria-label="Receipt header preview"><div class="panel-header"><div><h2>Receipt header preview</h2><p>Preview your company details before saving.</p></div></div><div class="receipt-preview-content"><div class="receipt-paper" id="receipt-settings-preview">${receiptBrandingHtml(settings)}<p>${escape(settings.receipt_footer ?? 'Thank you for shopping with us.')}<br>Payment record · not a fiscal invoice.</p></div><small id="receipt-preview-status" role="status">Saved company details · blank fields stay off receipts.</small></div></section><section class="panel plan-card"><h3>Sign-in accounts</h3><p>Connect your own Google or Apple account for future sign-ins.</p><a class="button" href="/account/connections">Manage sign-in accounts</a></section><section class="panel plan-card workspace-plan-card"><h3>Billing & plans</h3><p>Review your subscription and invoice records.</p></section><div class="settings-note">Only administrators can edit these details. Receipts use your company's current saved profile, including when you reprint an earlier sale.</div></div></div><section class="panel recent-sales" style="margin-top:22px"><div class="panel-header"><div><h2>Stock locations</h2><p>Each store, warehouse, or online location has its own stock balance.</p></div>${button('Add location', 'add-location', 'small', 'plus')}</div><div class="table-wrap"><table><thead><tr><th>Location</th><th>Type</th><th>Address / notes</th></tr></thead><tbody>${locations().map(location => `<tr><td><strong>${escape(location.name)}</strong></td><td>${statusBadge(location.type || 'Store')}</td><td>${escape(location.address || '—')}</td></tr>`).join('')}</tbody></table></div><div class="access-note">Commerce channels can use this location’s stock in Channels. This release simulates orders and catalog sync locally; no external storefront is connected.</div></section>`;
    }

    function navigate(section) {
        if (state.busy && state.section === 'settings') { toast('Please wait for your settings to finish saving.'); return; }
        if (!Object.hasOwn(sections, section)) { return; }
        state.saleRecordRequest += 1;
        state.section = section;
        state.search = '';
        state.category = 'All products';
        state.productFamily = 'all';
        history.pushState({}, '', `/app/${section}${section === 'reports' && state.reportView === 'financial' && canManage() ? '?view=financial' : ''}`);
        renderSection();
        closeSidebar();
        window.scrollTo({top: 0, behavior: 'instant'});
    }

    function toast(message, error = false) {
        const element = document.createElement('div');
        element.className = `toast ${error ? 'error' : ''}`;
        element.innerHTML = `${icon(error ? 'alert' : 'check')}<span>${escape(message)}</span>`;
        document.getElementById('toast-container').append(element);
        setTimeout(() => element.remove(), error ? 7000 : 4000);
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebar-shade').classList.remove('open');
        document.getElementById('menu-button').setAttribute('aria-expanded', 'false');
    }

    function openModal(title, subtitle, body) {
        state.saleRecordRequest += 1;
        modalContent.innerHTML = `<div class="modal-header"><div><h2 id="modal-title">${title}</h2><p>${subtitle}</p></div><button class="icon-button" data-action="close-modal" aria-label="Close dialog">${icon('close')}</button></div><div class="modal-body">${body}</div>`;
        modalContent.querySelectorAll('[data-action="print-receipt"]').forEach(control => { control.disabled = state.receiptPrinting; });
        modal.classList.toggle('variant-dialog', Boolean(modalContent.querySelector('.variant-builder, .variant-family-list')));
        if (!modal.open) { modal.showModal(); }
    }

    function modalForm(form, fields, submit = 'Save', extra = '') {
        return `<form data-form="${form}" ${extra}><div class="form-error" hidden role="alert"></div>${fields}<div class="form-actions">${button('Cancel', 'close-modal')}<button class="button primary" type="submit">${submit}</button></div></form>`;
    }

    function productForm(product = null) {
        const categories = [...new Set(products().map(item => item.category).filter(Boolean))];
        const linked = Boolean(product?.product_family_id);
        const options = linked ? (product.option_names || productFamilyById(product.product_family_id)?.option_names || []).map(axis => field(axis === 'size' ? 'Size' : 'Color option', `option_${axis}`, product.variant_options?.[axis] || '', 'text', 'required maxlength="30"', 'Must be unique with the other options in this family.')).join('') : field('Variant / attributes', 'variant', product?.variant || '', 'text', 'maxlength="100"', 'For example: Sage / Medium.');
        openModal(product ? 'Edit product' : 'Add product', product ? 'Update product details. Use Adjust to change stock quantities.' : 'Enter product details and opening stock.', modalForm(product ? 'edit-product' : 'add-product', `${linked ? `<div class="modal-tip">Part of <strong>${escape(product.family_name)}</strong>. Size and color update this SKU’s display name; its family and stock stay the same. Past receipts keep their original labels.</div>` : ''}<div class="form-grid">${field('Product name', 'name', product?.name || '', 'text', `required maxlength="150" ${linked ? 'readonly' : ''}`, linked ? 'Generated from its family and option values.' : '', true)}${field('SKU', 'sku', product?.sku || '', 'text', 'required maxlength="80"', 'A unique product code.')}${field('Barcode', 'barcode', product?.barcode || '', 'text', 'maxlength="80"', 'Optional. Match your scanner label.')}${field('Category', 'category', product?.category || '', 'text', 'required maxlength="80" list="category-options"')}<datalist id="category-options">${categories.map(category => `<option value="${escape(category)}">`).join('')}</datalist>${options}${field('Selling price (₱)', 'price', product?.price ?? '', 'number', 'required min="0" max="1000000" step="0.01"')}${field('Wholesale price (₱)', 'wholesale_price', product?.wholesale_price ?? '', 'number', 'min="0" max="1000000" step="0.01"', 'Optional. Blank means unavailable for wholesale checkout.')}${field('Unit cost (₱)', 'cost', product?.cost ?? '', 'number', 'required min="0" max="1000000" step="0.01"')}${field('Reorder level', 'reorder_level', product?.reorder_level ?? 5, 'number', 'required min="0" max="100000" step="1"', 'Alert when available stock reaches this quantity.')}${product ? field('Illustration color', 'color', /^#[\da-f]{6}$/i.test(product.color || '') ? product.color : '#95886b', 'color', '', 'Only the product illustration; independent of the Color option.') : field('Opening stock', 'quantity', 0, 'number', 'required min="0" max="100000" step="1"', `Added to ${escape(currentLocation()?.name)}.`)}${!product ? field('Illustration color', 'color', '#95886b', 'color', '') : ''}</div>`, product ? 'Save product' : 'Add product', product ? `data-id="${number(product.id)}"` : ''));
    }

    const variantAxes = ['size', 'color'];
    const variantLabel = options => variantAxes.filter(axis => options[axis]).map(axis => `${axis === 'size' ? 'Size' : 'Color'} ${options[axis]}`).join(' · ');
    const variantCombinationKey = options => variantAxes.map(axis => String(options[axis] || '').trim().replace(/\s+/g, ' ').toLowerCase()).join('\u0000');
    const variantSlug = value => String(value || '').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').toUpperCase();

    function variantBuilder(product = null, family = null) {
        if (!canManage()) { return; }
        if (state.pendingRecordAction) { recordRecoveryModal(); return; }
        const append = Boolean(family);
        const defaults = append ? family.default_values : product || {};
        if (append && !defaults) { toast('Refresh the workspace to load this family’s defaults before adding variants.', true); return; }
        const axes = append ? family.option_names : variantAxes;
        const name = family?.name || product?.name || '';
        const prefix = variantSlug(product?.sku || name || 'PRODUCT').slice(0, 40);
        const categories = [...new Set(products().map(item => item.category).filter(Boolean))];
        const basics = append ? `<div class="variant-defaults"><strong>${escape(family.name)}</strong><span>${escape(defaults.category)} · ${money(defaults.price)} retail · ${money(defaults.cost)} cost${defaults.wholesale_price != null ? ` · ${money(defaults.wholesale_price)} wholesale` : ''}</span><small>New SKUs inherit these original family defaults, including a reorder level of ${count(defaults.reorder_level)}. Review or override each selling price below. Edit a saved SKU to change its other details.</small></div>` : `<div class="form-grid">${field('Product family name', 'name', name.slice(0, 80), 'text', 'required maxlength="80"', 'The shared name, such as Everyday tee.', true)}${field('Category', 'category', defaults.category || '', 'text', 'required maxlength="80" list="variant-categories"')}<datalist id="variant-categories">${categories.map(category => `<option value="${escape(category)}">`).join('')}</datalist>${field('Default selling price (₱)', 'price', defaults.price ?? '', 'number', 'required min="0" max="1000000" step="0.01"')}${field('Default wholesale price (₱)', 'wholesale_price', defaults.wholesale_price ?? '', 'number', 'min="0" max="1000000" step="0.01"', 'Optional. Each SKU can be priced independently later.')}${field('Default unit cost (₱)', 'cost', defaults.cost ?? '', 'number', 'required min="0" max="1000000" step="0.01"')}${field('Reorder level', 'reorder_level', defaults.reorder_level ?? 5, 'number', 'required min="0" max="100000" step="1"', 'Applies separately to every new SKU’s available stock.')}${field('Illustration color', 'color', /^#[\da-f]{6}$/i.test(defaults.color || '') ? defaults.color : '#95886b', 'color', '', 'A visual swatch, separate from the Color option.')}</div>`;
        openModal(append ? `Add variants to ${escape(family.name)}` : 'Create product variants', append ? 'Keep the family’s option types. Add only the new combinations you need.' : 'Create size and color combinations with their own SKUs and stock.', modalForm('variant-family', `<div class="variant-builder">${product ? `<div class="modal-tip"><strong>${escape(product.sku)}</strong> stays a standalone product with its existing stock. This creates new SKUs; opening stock is entered separately and is not copied.</div>` : '<div class="modal-tip">Each combination is a separate SKU for checkout, stock, purchasing and channels. Opening quantities are physical stock you actually have.</div>'}${basics}<div class="variant-builder-divider"><h3>${append ? 'New combinations' : 'Choose your options'}</h3><p>${append ? 'Existing combinations are skipped. The family’s option types stay fixed.' : 'Use Size, Color, or both. Separate values with commas; leave an unused option empty.'}</p></div><div class="form-grid">${axes.map(axis => field(axis === 'size' ? 'Sizes' : 'Colors', `values_${axis}`, '', 'text', `${append ? 'required ' : ''}maxlength="4000" placeholder="${axis === 'size' ? 'S, M, L' : 'Black, Sand'}"`, 'Up to 30 characters per value.')).join('')}${field('SKU prefix', 'sku_prefix', prefix, 'text', 'required maxlength="40"', 'Generated codes remain editable.', true)}${selectField('Opening stock location', 'location_id', locationOptions(), 'All opening quantities in this batch go to this location.', true)}</div><div class="variant-generate"><span>Generate up to 100 combinations. Generating again replaces row edits.</span>${button('Generate combinations', 'generate-variants', '', 'stock')}</div><div id="variant-matrix" class="variant-matrix" aria-live="polite"><div class="variant-matrix-placeholder">Your new SKUs will appear here for review.</div></div></div>`, append ? 'Add variants' : 'Create variants', `data-key="${uuid()}" ${append ? `data-family-id="${number(family.id)}" data-axes="${escape(JSON.stringify(axes))}"` : ''}`));
        modalContent.querySelector('button[type="submit"]').disabled = true;
        modalContent.querySelector('[name="sku_prefix"]').dataset.automatic = product || append ? 'false' : 'true';
    }

    function variantMatrixRow(options, sku, price) {
        const input = (label, name, value, attributes) => `<label><span>${label}</span><input name="${name}" value="${escape(value)}" ${attributes}></label>`;
        return `<article class="variant-matrix-row" data-options="${escape(JSON.stringify(options))}"><div class="variant-row-title"><strong>${escape(variantLabel(options))}</strong><button type="button" data-action="remove-variant-row" aria-label="Remove ${escape(variantLabel(options))}">${icon('close')}</button></div><div class="variant-row-fields">${input('SKU', 'sku', sku, 'type="text" required maxlength="80"')}${input('Barcode · optional', 'barcode', '', 'type="text" maxlength="80"')}${input('Selling price (₱)', 'variant_price', price, 'type="number" required min="0" max="1000000" step="0.01"')}${input('Opening stock', 'variant_quantity', 0, 'type="number" required min="0" max="100000" step="1"')}</div></article>`;
    }

    function variantBuilderSignature(form) {
        return JSON.stringify(['size', 'color'].map(axis => form.elements.namedItem(`values_${axis}`)?.value.trim() || '').concat(form.elements.namedItem('sku_prefix').value.trim()));
    }

    function updateVariantRowCount(form) {
        const rows = form.querySelectorAll('.variant-matrix-row');
        const counter = form.querySelector('#variant-row-count');
        if (counter) { counter.textContent = `${count(rows.length)} ${rows.length === 1 ? 'SKU' : 'SKUs'} to create`; }
        form.querySelector('button[type="submit"]').disabled = !rows.length || form.dataset.generatedSignature !== variantBuilderSignature(form);
    }

    function generateVariantRows(form) {
        if (state.pendingRecordAction) { recordRecoveryModal(); return; }
        const error = form.querySelector('.form-error'); error.hidden = true;
        try {
            const prefixInput = form.elements.namedItem('sku_prefix');
            if (!prefixInput.reportValidity()) { return; }
            const options = {};
            const axes = form.dataset.familyId ? JSON.parse(form.dataset.axes) : variantAxes;
            axes.forEach(axis => {
                const raw = form.elements.namedItem(`values_${axis}`).value.trim();
                if (!raw) { if (form.dataset.familyId) { throw new Error(`Enter at least one ${axis} value for this family.`); } return; }
                const values = raw.split(',').map(value => value.trim().replace(/\s+/g, ' '));
                if (values.some(value => !value || value.length > 30)) { throw new Error(`Each ${axis} value must contain 1–30 characters. Remove empty values between commas.`); }
                if (new Set(values.map(value => value.toLowerCase())).size !== values.length) { throw new Error(`Each ${axis} value must appear once, regardless of letter case.`); }
                options[axis] = values;
            });
            const usedAxes = variantAxes.filter(axis => options[axis]);
            if (!usedAxes.length) { throw new Error('Enter sizes, colors, or both before generating combinations.'); }
            const countCombinations = usedAxes.reduce((total, axis) => total * options[axis].length, 1);
            if (countCombinations > 100) { throw new Error('This batch has more than 100 combinations. Use fewer values and add another batch later.'); }
            let combinations = [{}];
            usedAxes.forEach(axis => { combinations = combinations.flatMap(current => options[axis].map(value => ({...current, [axis]: value}))); });
            const existing = new Set(form.dataset.familyId ? familyProducts(form.dataset.familyId).map(product => variantCombinationKey(product.variant_options || {})) : []);
            const before = combinations.length;
            combinations = combinations.filter(options => !existing.has(variantCombinationKey(options)));
            const usedSkus = new Set(products().flatMap(product => [product.sku, product.barcode]).filter(Boolean).map(value => value.trim()));
            const prefix = prefixInput.value.trim();
            const basePrice = form.dataset.familyId ? productFamilyById(form.dataset.familyId)?.default_values?.price ?? '' : form.elements.namedItem('price').value;
            const rows = combinations.map((options, index) => {
                const suffix = variantSlug(Object.values(options).join('-')) || String(index + 1).padStart(2, '0');
                const base = `${prefix}-${suffix}`.slice(0, 74);
                let sku = base; let sequence = 2;
                while (usedSkus.has(sku)) { sku = `${base}-${sequence++}`; }
                usedSkus.add(sku);
                return variantMatrixRow(options, sku, basePrice);
            });
            form.dataset.generatedSignature = variantBuilderSignature(form);
            form.dataset.optionNames = JSON.stringify(usedAxes);
            form.querySelector('#variant-matrix').innerHTML = `<div class="variant-matrix-heading"><strong id="variant-row-count">${count(rows.length)} ${rows.length === 1 ? 'SKU' : 'SKUs'} to create</strong><span>${before - rows.length ? `${count(before - rows.length)} existing combinations skipped. ` : ''}Review codes, prices and physical stock.</span></div>${rows.length ? rows.join('') : '<div class="variant-matrix-placeholder">These combinations already exist. Enter a new size or color to add more.</div>'}`;
            updateVariantRowCount(form);
        } catch (failure) { error.textContent = failure.message; error.hidden = false; }
    }

    function manageVariants(family) {
        if (!family || !canManage()) { return; }
        const siblings = familyProducts(family.id);
        openModal(escape(family.name), `${count(siblings.length)} separate SKUs · ${escape(locationName(state.location))}`, `<div class="variant-family-list"><div class="modal-tip">Each SKU has its own barcode, prices and stock. Family option types stay ${escape(family.option_names.map(axis => axis === 'size' ? 'Size' : 'Color').join(' and '))}; edit individual values on a SKU.</div><div class="table-wrap"><table><thead><tr><th>Variant / SKU</th><th>Selling price</th><th>Physical / available</th><th></th></tr></thead><tbody>${siblings.map(product => `<tr><td><strong>${escape(variantLabel(product.variant_options || {}))}</strong><small class="table-secondary">${escape(product.sku)}${product.barcode ? ` · ${escape(product.barcode)}` : ''}</small></td><td>${money(product.price)}</td><td>${count(stock(product))} / ${count(availableStock(product))}</td><td>${button('Edit SKU', 'edit-product', 'small', '', `data-id="${number(product.id)}" ${!canWrite() ? 'disabled' : ''}`)}</td></tr>`).join('')}</tbody></table></div><div class="form-actions">${button('Close', 'close-modal')}${button('Add variants', 'append-variants', 'primary', 'plus', `data-family-id="${number(family.id)}" ${!canWrite() ? 'disabled' : ''}`)}</div></div>`);
    }

    function finishVariantAction(result) {
        const created = Array.isArray(result.products) ? result.products.length : 0;
        toast(`${count(created)} ${created === 1 ? 'variant is' : 'variants are'} ready. Each SKU has its own stock.`);
        const family = productFamilyById(result.id);
        if (family) { manageVariants(family); }
    }

    async function submitVariantFamily(form, data) {
        if (!canManage()) { return; }
        if (state.pendingRecordAction) {
            const pending = state.pendingRecordAction;
            if (!pending.path.startsWith('/product-families')) { recordRecoveryModal(); return; }
            const result = await saveRecordAction(form, pending.path, pending.payload, pending.label);
            if (result) { finishVariantAction(result); }
            return;
        }
        const error = form.querySelector('.form-error'); error.hidden = true;
        try {
            const rows = [...form.querySelectorAll('.variant-matrix-row')];
            if (!rows.length || rows.length > 100 || form.dataset.generatedSignature !== variantBuilderSignature(form)) { throw new Error('Generate and review 1–100 combinations before saving.'); }
            const variants = rows.map(row => ({options: JSON.parse(row.dataset.options), sku: row.querySelector('[name="sku"]').value.trim(), barcode: row.querySelector('[name="barcode"]').value.trim() || null, price: number(row.querySelector('[name="variant_price"]').value), quantity: number(row.querySelector('[name="variant_quantity"]').value)}));
            const skuKeys = variants.map(variant => variant.sku.toLowerCase());
            if (new Set(skuKeys).size !== skuKeys.length) { throw new Error('Each new SKU must be unique. Review duplicate codes before saving.'); }
            const barcodes = variants.filter(variant => variant.barcode).map(variant => variant.barcode.toLowerCase());
            if (new Set(barcodes).size !== barcodes.length) { throw new Error('Each barcode must belong to one SKU. Review duplicate barcodes before saving.'); }
            const seen = new Map();
            variants.forEach((variant, index) => {
                const identifiers = [...new Set([variant.sku, variant.barcode].filter(Boolean))];
                identifiers.forEach(identifier => {
                    const key = identifier.toLowerCase();
                    if (seen.has(key) && seen.get(key) !== index) { throw new Error('A SKU or barcode is used by another row. Give each new variant its own scanner code.'); }
                    seen.set(key, index);
                    if (products().some(product => product.sku === identifier || product.barcode === identifier)) { throw new Error('A SKU or barcode already belongs to another product. Review the scanner codes before saving.'); }
                });
            });
            const append = Boolean(form.dataset.familyId);
            const payload = {location_id: number(data.location_id), idempotency_key: form.dataset.key, variants, ...(!append ? {name: data.name.trim(), option_names: JSON.parse(form.dataset.optionNames), category: data.category.trim(), price: number(data.price), cost: number(data.cost), wholesale_price: data.wholesale_price === '' ? null : number(data.wholesale_price), reorder_level: number(data.reorder_level), color: data.color} : {})};
            const result = await saveRecordAction(form, append ? `/product-families/${form.dataset.familyId}/variants` : '/product-families', payload, append ? 'New variants' : 'Variant family', {quiet: true, context: {location_id: payload.location_id, family_name: append ? productFamilyById(form.dataset.familyId)?.name : payload.name}});
            if (result) { finishVariantAction(result); }
        } catch (failure) { error.textContent = failure.message; error.hidden = false; }
    }

    function adjustModal(product) {
        if (state.pendingRecordAction) { recordRecoveryModal(); return; }
        openModal('Adjust stock', escape(product.name), modalForm('adjust', `<div class="modal-tip">At ${escape(currentLocation()?.name)}: <strong>${stock(product)} physical · ${reservedStock(product)} reserved · ${availableStock(product)} available</strong>. Reserved units cannot be removed.</div><div class="form-grid">${field('Stock change', 'quantity', '', 'number', 'required step="1"', 'Use 10 to add ten units, or -3 to remove three.', true)}${field('Reason', 'reason', '', 'text', 'required maxlength="200" placeholder="For example: Stock count correction"', 'Saved in your stock movement history.', true)}</div>`, 'Update stock', `data-id="${product.id}" data-key="${uuid()}" data-location="${state.location}"`));
    }

    function transferModal(product) {
        if (state.pendingRecordAction) { recordRecoveryModal(); return; }
        if (locations().length < 2) { toast('Add another location in Settings before transferring stock.', true); return; }
        openModal('Transfer stock', escape(product.name), modalForm('transfer', `<div class="modal-tip">From <strong>${escape(currentLocation()?.name)}</strong> · ${availableStock(product)} unreserved units available.</div><div class="form-grid">${selectField('To location', 'to_location_id', locationOptions(null, state.location), '', true)}${field('Quantity to transfer', 'quantity', 1, 'number', `required min="1" max="${availableStock(product)}" step="1"`, 'The source and destination update together.', true)}</div>`, 'Transfer stock', `data-id="${product.id}" data-key="${uuid()}" data-location="${state.location}"`));
    }

    function addCustomerModal() {
        openModal('Add customer', 'Customer details are optional at checkout.', modalForm('customer', `<div class="form-grid">${field('Customer name', 'name', '', 'text', 'required maxlength="100"', '', true)}${field('Email address', 'email', '', 'email', 'maxlength="180"')}${field('Phone number', 'phone', '', 'tel', 'maxlength="30"')}</div>`, 'Add customer'));
    }

    function paymentDetails() {
        const totals = cartTotals();
        if (state.payment === 'split') {
            return `<div class="form-grid">${field('Cash received (₱)', 'cash_amount', totals.total, 'number', 'required min="0" step="0.01"', '', true)}${field('Card paid (₱)', 'card_amount', 0, 'number', 'required min="0" step="0.01"')}${field('E-wallet paid (₱)', 'wallet_amount', 0, 'number', 'required min="0" step="0.01"')}</div><div class="payment-summary" id="change-display"><span>Change due</span><b>${money(0)}</b></div><div class="modal-tip">Confirm card and wallet payments on your separate terminal or app before completing this sale.</div>`;
        }
        if (state.payment === 'cash') {
            return `${field('Cash received (₱)', 'cash_amount', totals.total, 'number', `required min="${totals.total}" step="0.01" autofocus`)}<div class="payment-summary" id="change-display"><span>Change due</span><b>${money(0)}</b></div>`;
        }
        return `${field(`${state.payment === 'card' ? 'Card' : 'E-wallet'} payment (₱)`, 'payment_amount', totals.total, 'number', 'readonly')}<div class="modal-tip">This records a payment. Confirm payment through your ${state.payment === 'card' ? 'separate card terminal' : 'wallet app'} first. No payment is charged by POSONLY.</div>`;
    }

    function paymentModal() {
        if (!state.cart.length) { return; }
        if (state.pendingSale) {
            openModal('Check sale status', 'Retrying the same request cannot create a second sale.', `<form data-form="payment" data-recovery="true"><div class="form-error" hidden role="alert"></div><div class="payment-total">${money(state.pendingSaleTotals?.total)}</div><p class="payment-subtitle">This checkout’s original payment is kept unchanged.</p><div class="purchase-order-items">${state.pendingSale.payments.map(payment => `<div class="receipt-line"><strong>${{cash: 'Cash', card: 'Card', wallet: 'E-wallet'}[payment.method]}</strong><span>${money(payment.amount)}</span></div>`).join('')}</div><div class="modal-tip">If the sale was already saved, we’ll retrieve its receipt. If it wasn’t saved, we’ll complete the original checkout once. Keep this cart until the result is confirmed.</div><div class="form-actions">${button('Close for now', 'close-modal')}<button class="button primary" type="submit">Check sale status ${icon('refresh')}</button></div></form>`);
            return;
        }
        if (!canWrite()) { toast('Sales are paused. Review Billing & plans before checkout.', true); return; }
        if (state.cart.some(item => !productById(item.product_id) || item.quantity > availableStock(productById(item.product_id)) || !hasTierPrice(productById(item.product_id)))) { toast('Review your cart: stock or wholesale pricing has changed. Adjust the quantities or catalog prices before checkout.', true); return; }
        state.payment = 'cash';
        state.saleKey ||= window.crypto?.randomUUID ? crypto.randomUUID() : `sale-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        openModal('Record payment', `${itemsLabel(state.cart.reduce((sum, item) => sum + item.quantity, 0))} · ${escape(currentLocation()?.name)}`, `<form data-form="payment"><div class="form-error" hidden role="alert"></div><div class="payment-total">${money(cartTotals().total)}</div><p class="payment-subtitle">Choose how your customer is paying.</p><div class="payment-tabs">${['cash', 'card', 'wallet', 'split'].map(method => `<button type="button" data-action="payment-method" data-method="${method}" class="${method === 'cash' ? 'active' : ''}" aria-pressed="${method === 'cash'}">${icon(method)}<span>${{cash: 'Cash', card: 'Card', wallet: 'E-wallet', split: 'Split'}[method]}</span></button>`).join('')}</div><div class="payment-details" id="payment-details">${paymentDetails()}</div><div class="form-actions">${button('Back to cart', 'close-modal')}<button class="button primary" type="submit">Complete sale</button></div></form>`);
    }

    function receiptHtml(sale) {
        const customer = (state.data.customers || []).find(item => number(item.id) === number(sale.customer_id));
        return `<div class="receipt-paper" id="receipt-paper">${receiptBrandingHtml()}<div class="receipt-meta">${escape(locationName(sale.location_id))}<br>${escape(sale.number || `#${sale.id}`)} · ${time(sale.created_at)}<br>${escape(sale.cashier_name || state.data.user.name)}</div>${sale.status !== 'completed' ? `<p><strong>${sale.status === 'returned' ? 'RETURNED SALE' : 'PARTLY RETURNED SALE'}</strong></p>` : ''}${isChannelSale(sale) ? `<div class="receipt-line"><span>Channel</span><strong>${escape(sale.channel_name || 'Commerce')}</strong></div><div class="receipt-line"><span>External reference</span><strong>${escape(sale.external_reference)}</strong></div><p>Local simulated payment · no real charge.</p>` : ''}<div class="receipt-line"><span>Price tier</span><strong>${sale.price_tier === 'wholesale' ? 'Wholesale' : 'Retail'}</strong></div>${customer ? `<div class="receipt-line"><span>Customer</span><strong>${escape(customer.name)}</strong></div>` : ''}<div class="receipt-items">${(sale.items || []).map(item => `<div class="receipt-item"><div class="receipt-line"><strong>${escape(item.name)}</strong><span>${money(item.line_total)}</span></div><small>${item.quantity} × ${money(item.unit_price)} · ${escape(item.sku)}${number(item.returned_quantity) ? `<br>${number(item.returned_quantity)} returned` : ''}</small></div>`).join('')}</div><div class="receipt-line"><span>Subtotal</span><span>${money(sale.subtotal)}</span></div>${number(sale.discount) ? `<div class="receipt-line"><span>Discount (${sale.discount_percent}%)</span><span>−${money(sale.discount)}</span></div>` : ''}<div class="receipt-line"><span>Tax (${sale.tax_percent}%)</span><span>${money(sale.tax)}</span></div><div class="receipt-line total"><strong>Original total</strong><strong>${money(sale.total)}</strong></div>${number(sale.returned_amount) ? `<div class="receipt-line"><span>Returns recorded</span><span>−${money(sale.returned_amount)}</span></div><div class="receipt-line total"><strong>Remaining sale total</strong><strong>${money(saleNet(sale))}</strong></div>` : ''}${(sale.payments || []).map(payment => `<div class="receipt-line"><span>${{cash: 'Cash', card: 'Card', wallet: 'E-wallet'}[payment.method] || escape(payment.method)}${payment.simulated ? ' · simulated' : ''}</span><span>${money(payment.amount)}</span></div>`).join('')}<div class="receipt-line"><span>Change</span><span>${money(sale.change)}</span></div><p>${escape(state.data.settings?.receipt_footer ?? 'Thank you. See you again soon.')}<br>Payment record · not a fiscal invoice.</p></div>`;
    }

    function receiptModal(sale, completed = false) {
        state.lastSale = sale;
        openModal(completed ? 'Sale completed' : 'Receipt', escape(sale.number || `Sale #${sale.id}`), `${completed ? `<div class="receipt-success"><span>${icon('check')}</span><h3>Sale complete.</h3><p>Your stock has been updated.<br>Change due: <strong>${money(sale.change)}</strong></p></div>` : ''}${receiptHtml(sale)}${sale.returns?.length ? `<div class="return-history"><h3>Return records</h3>${sale.returns.map(record => `<div><strong>${escape(record.number)} · ${money(record.total)}</strong><small>${time(record.created_at)} · ${escape(record.actor_name || 'Team member')}<br>${escape(record.reason || 'Full remaining return')}</small></div>`).join('')}</div>` : ''}<div class="form-actions receipt-actions">${button('Preview print layout', 'preview-receipt-print', 'subtle', 'receipt')}${button('Print receipt', 'print-receipt', '', 'print')}${button(completed ? 'Next sale' : 'Close', 'close-modal', 'primary')}</div>`);
    }

    function orderLine() {
        return `<div class="purchase-line"><select name="product_id" required aria-label="Product"><option value="">Choose product</option>${products().map(product => `<option value="${product.id}" data-cost="${number(product.cost)}">${escape(product.name)}</option>`).join('')}</select><input name="quantity" type="number" min="1" step="1" value="1" required aria-label="Quantity"><input name="cost" type="number" min="0" step="0.01" value="0" required aria-label="Unit cost in pesos"><button type="button" data-action="remove-order-line" aria-label="Remove product line">×</button></div>`;
    }

    function createOrderModal() {
        if (state.pendingRecordAction) { recordRecoveryModal(); return; }
        if (!products().length) { toast('Add a product before creating a purchase order.', true); productForm(); return; }
        if (!(state.data.suppliers || []).length) { supplierModal(true); return; }
        openModal('Create purchase order', 'Create an order, then record each supplier delivery.', modalForm('purchase', `<div class="form-grid">${selectField('Supplier', 'supplier_id', (state.data.suppliers || []).map(supplier => `<option value="${supplier.id}">${escape(supplier.name)}</option>`).join(''))}${selectField('Delivery location', 'location_id', locationOptions())}${selectField('Order status', 'status', '<option value="ordered">Approved · awaiting delivery</option><option value="draft">Draft · approve later</option>', 'Drafts cannot be received until approved.', true)}</div><div class="purchase-line-header"><span>Product</span><span>Qty.</span><span>Cost (₱)</span><span></span></div><div id="purchase-lines">${orderLine()}</div>${button('Add another product', 'add-order-line', 'small subtle', 'plus')}<div class="purchase-total"><span>Order total</span><b id="purchase-total">${money(0)}</b></div>`, 'Create purchase order', `data-key="${uuid()}"`));
    }

    function supplierModal(needsSupplier = false) {
        openModal(needsSupplier ? 'Add a supplier' : 'Suppliers', 'Save supplier contact details for purchase orders.', `${!needsSupplier && state.data.suppliers.length ? `<div class="purchase-order-items">${state.data.suppliers.map(supplier => `<div class="receipt-line"><strong>${escape(supplier.name)}</strong><span>${escape(supplier.phone || supplier.email || '—')}</span></div>`).join('')}</div>` : ''}${modalForm('supplier', `<div class="form-grid">${field('Supplier name', 'name', '', 'text', 'required maxlength="100"', '', true)}${field('Email', 'email', '', 'email', 'maxlength="180"')}${field('Phone', 'phone', '', 'tel', 'maxlength="30"')}</div>`, 'Add supplier', needsSupplier ? 'data-next="purchase"' : '')}`);
    }

    function orderDetails(order, receive = false) {
        const supplier = state.data.suppliers.find(item => number(item.id) === number(order.supplier_id));
        if (receive && state.pendingRecordAction) { recordRecoveryModal(); return; }
        if (receive && !orderMayReceive(order)) { toast('Approve a draft before receiving it. Closed orders cannot receive stock.', true); return; }
        const lines = `<div class="purchase-order-items">${(order.items || []).map(item => `<div class="receipt-line"><div><strong>${escape(item.name)}</strong><br><small>${count(item.quantity)} ordered · ${count(item.received_quantity ?? (order.status === 'received' ? item.quantity : 0))} received · ${count(remainingOrderQuantity(item))} remaining<br>${money(item.unit_cost)} per unit</small></div><strong>${money(item.line_total)}</strong></div>`).join('')}<div class="receipt-line total"><span>Order total</span><strong>${money(order.total)}</strong></div></div>`;
        const title = receive ? 'Receive delivery' : escape(order.number || `Order #${order.id}`);
        const content = receive ? modalForm('receive', `<div class="modal-tip">Enter this delivery’s quantities. Stock is added to ${escape(locationName(order.location_id))}; remaining units stay outstanding.</div><div class="line-quantity-list">${(order.items || []).filter(item => remainingOrderQuantity(item) > 0).map(item => `<div class="line-quantity-row"><div><strong>${escape(item.name)}</strong><small>${count(remainingOrderQuantity(item))} still outstanding</small></div><label><span>Received qty.</span><input type="number" name="quantity_${number(item.product_id)}" data-product-id="${number(item.product_id)}" min="0" max="${remainingOrderQuantity(item)}" step="1" value="0" required aria-label="Received quantity for ${escape(item.name)}"></label></div>`).join('')}</div>${button('Receive all remaining', 'fill-remaining', 'small subtle')}<div class="form-grid" style="margin-top:18px">${field('Delivery note', 'note', '', 'text', 'maxlength="250"', 'Optional. For example: First carton received.', true)}</div>`, 'Record delivery', `data-id="${order.id}" data-key="${uuid()}"`) : `${statusBadge(orderStatuses[order.status] || order.status, order.status === 'received' ? 'green' : 'amber')}${lines}${order.receipts?.length ? `<div class="return-history"><h3>Delivery history</h3>${order.receipts.map(receipt => `<div><strong>${escape(receipt.number || `Delivery #${receipt.id}`)}</strong><small>${time(receipt.created_at)} · ${escape(receipt.received_by_name || receipt.actor_name || 'Team member')}${receipt.note ? `<br>${escape(receipt.note)}` : ''}</small>${(receipt.items || []).map(item => `<small>${escape(item.name || productById(item.product_id)?.name || 'Product')} · ${count(item.quantity)} units</small>`).join('')}</div>`).join('')}</div>` : ''}<p class="inline-subtitle">${order.status === 'cancelled' ? 'Outstanding quantities were canceled. Previously received stock stays in inventory.' : order.status === 'draft' ? 'Approve this draft when you are ready to place the order.' : order.status === 'received' ? 'All units have been received. Inventory is up to date.' : 'Receive each delivery after checking its actual quantities.'}</p><div class="form-actions purchase-detail-actions">${button('Close', 'close-modal')}${['draft', 'ordered', 'partially_received'].includes(order.status) ? button('Cancel outstanding', 'cancel-order', 'subtle', '', `data-id="${order.id}"`) : ''}${order.status === 'draft' ? button('Approve order', 'approve-order', 'primary', 'check', `data-id="${order.id}" ${!canWrite() ? 'disabled' : ''}`) : orderMayReceive(order) ? button('Receive delivery', 'receive-order', 'primary', 'check', `data-id="${order.id}" ${!canWrite() ? 'disabled' : ''}`) : ''}</div>`;
        openModal(title, `${escape(supplier?.name || 'Supplier')} → ${escape(locationName(order.location_id))}`, content);
    }

    function orderActionModal(order, action) {
        if (!order) { return; }
        const approve = action === 'approve-order';
        openModal(approve ? 'Approve purchase order?' : 'Cancel outstanding items?', escape(order.number), modalForm(action, `<p class="danger-copy">${approve ? 'This approves the draft for delivery. Stock is added only when you record a receipt.' : 'This closes the outstanding part of this order. Stock from any earlier deliveries stays in inventory.'}</p>`, approve ? 'Approve order' : 'Cancel outstanding', `data-id="${order.id}"`));
    }

    function purchaseTotal() {
        const total = [...document.querySelectorAll('.purchase-line')].reduce((sum, line) => sum + number(line.querySelector('[name="quantity"]').value) * number(line.querySelector('[name="cost"]').value), 0);
        const display = document.getElementById('purchase-total');
        if (display) { display.textContent = money(total); }
    }

    const remainingSaleQuantity = item => number(item.returnable_quantity ?? Math.max(0, number(item.quantity) - number(item.returned_quantity)));
    const remainingOrderQuantity = item => number(item.remaining_quantity ?? Math.max(0, number(item.quantity) - number(item.received_quantity)));
    const cents = value => Math.round(number(value) * 100);
    const portion = (value, numerator, denominator) => denominator > 0 ? Number((BigInt(value) * BigInt(numerator) * 2n + BigInt(denominator)) / (BigInt(denominator) * 2n)) : 0;

    function itemRefund(item, quantity) {
        const already = number(item.returned_quantity), original = number(item.quantity);
        const discount = cents(item.discount), tax = cents(item.tax);
        return (cents(item.unit_price) * quantity - (portion(discount, already + quantity, original) - portion(discount, already, original)) + (portion(tax, already + quantity, original) - portion(tax, already, original))) / 100;
    }

    function returnModal(sale) {
        if (state.pendingRecordAction) { recordRecoveryModal(); return; }
        const items = (sale.items || []).filter(item => remainingSaleQuantity(item) > 0);
        if (!items.length) { toast('All items in this sale have already been returned.'); return; }
        openModal('Record a return', escape(sale.number || `Sale #${sale.id}`), modalForm('return', `<div class="modal-tip">Choose the quantity your customer is returning. Original prices, discounts and tax determine the refund; stock returns to ${escape(locationName(sale.location_id))}.</div><div class="line-quantity-list">${items.map(item => `<div class="line-quantity-row"><div><strong>${escape(item.name)}</strong><small>${remainingSaleQuantity(item)} available to return · ${number(item.returned_quantity)} already returned</small></div><label><span>Return qty.</span><input type="number" name="quantity_${number(item.product_id)}" data-product-id="${number(item.product_id)}" min="0" max="${remainingSaleQuantity(item)}" step="1" value="0" required aria-label="Return quantity for ${escape(item.name)}"></label></div>`).join('')}</div>${button('Return all remaining', 'fill-remaining', 'small subtle')}<div class="form-grid" style="margin-top:18px">${field('Reason for return', 'reason', '', 'text', 'required maxlength="200"', 'Saved with this return record.', true)}</div><div class="payment-summary"><span>Refund to record</span><b id="return-preview">${money(0)}</b></div><div class="modal-tip" style="margin-top:18px">Refund the customer through the original payment provider separately. POSONLY records the return and restores stock; no payment is refunded by this app.</div>`, 'Record return', `data-id="${sale.id}" data-key="${uuid()}"`));
        returnFormSales.set(modal.querySelector('form[data-form="return"]'), sale);
    }

    function updateReturnPreview(form) {
        const sale = returnFormSales.get(form);
        const total = [...form.querySelectorAll('[data-product-id]')].reduce((sum, input) => {
            const item = sale?.items.find(item => number(item.product_id) === number(input.dataset.productId));
            const quantity = Math.trunc(Math.min(number(input.max), Math.max(0, number(input.value))));
            return sum + (item ? cents(itemRefund(item, quantity)) : 0);
        }, 0);
        const display = form.querySelector('#return-preview'); if (display) { display.textContent = money(total / 100); }
    }

    function recordStorageKey() { return `stockroom-pending-record:${state.data.tenant.id}:${state.data.user.id}`; }

    function restoreRecordAction() {
        if (state.recordActionRestored) { return; }
        state.recordActionRestored = true;
        try {
            const pending = JSON.parse(sessionStorage.getItem(recordStorageKey()) || 'null');
            if (pending && /^(?:\/products\/\d+\/adjust|\/transfers|\/purchase-orders|\/(?:sales|purchase-orders)\/\d+\/(?:return|receive)|\/cash-sessions\/(?:open|\d+\/(?:adjustments|close))|\/commerce\/(?:channels(?:\/\d+(?:\/(?:pause|resume|sync|orders))?)?|orders\/\d+\/(?:import|cancel))|\/product-families(?:\/\d+\/variants)?)$/.test(pending.path) && (pending.payload?.idempotency_key || pending.recovery_key) && ['POST', 'PATCH'].includes(pending.method || 'POST')) { state.pendingRecordAction = {...pending, uncertain: true}; if (pending.path.startsWith('/commerce/')) { state.commerceChannel = number(pending.context?.channel_id) || null; if (!state.cart.length && pending.context?.location_id) { state.location = number(pending.context.location_id); } } }
        } catch { /* Retain in-memory recovery when storage is unavailable. */ }
    }

    function clearRecordAction() {
        state.pendingRecordAction = null;
        try { sessionStorage.removeItem(recordStorageKey()); } catch { /* Browser storage may be unavailable. */ }
    }

    function recordRecoveryModal() {
        const pending = state.pendingRecordAction; if (!pending) { return; }
        const commerce = pending.path.startsWith('/commerce/');
        const variants = pending.path.startsWith('/product-families');
        const adjustment = pending.path.match(/^\/products\/(\d+)\/adjust$/);
        const transfer = pending.path === '/transfers';
        const stockProductId = adjustment ? number(adjustment[1]) : number(pending.payload.product_id);
        const rows = adjustment || transfer ? `<div class="receipt-line"><strong>${escape(productById(stockProductId)?.name || `Product #${stockProductId}`)}</strong><span>${adjustment && number(pending.payload.quantity) > 0 ? '+' : ''}${count(pending.payload.quantity)} units</span></div><small class="table-secondary">${escape(locationName(pending.payload.location_id ?? pending.payload.from_location_id))}${transfer ? ` → ${escape(locationName(pending.payload.to_location_id))}` : ''}</small>${pending.payload.reason ? `<small class="table-secondary">${escape(pending.payload.reason)}</small>` : ''}` : variants ? `<strong>${escape(pending.context?.family_name || pending.payload.name || 'Product family')}</strong><small class="table-secondary">Opening stock at ${escape(locationName(pending.payload.location_id))}</small>${(pending.payload.variants || []).map(item => `<div class="receipt-line"><div><strong>${escape(item.sku)}</strong><small class="table-secondary">${escape(variantLabel(item.options || {}))}</small></div><span>${count(item.quantity)} units</span></div>`).join('')}` : (pending.payload.items || []).map(item => `<div class="receipt-line"><strong>${escape(productById(item.product_id)?.name || products().find(product => product.sku === item.sku)?.name || item.sku || `Product #${item.product_id}`)}</strong><span>${count(item.quantity)} units</span></div>`).join('');
        const details = rows || (commerce ? `<strong>${escape(pending.payload.name || pending.payload.external_reference || pending.label)}</strong><small class="table-secondary">${escape(locationName(pending.context?.location_id))} · original request kept unchanged</small>` : `<div class="receipt-line"><strong>${escape(pending.label)}</strong><span>${money(pending.payload.amount ?? pending.payload.opening_float ?? pending.payload.counted_cash)}</span></div>${pending.payload.reason ? `<small>${escape(pending.payload.reason)}</small>` : ''}`);
        openModal('Check update status', 'The original request is kept unchanged for a safe retry.', modalForm('record-recovery', `<div class="modal-tip">${escape(pending.label)} may already have been recorded. Retry the same request to retrieve its result or save it once. Confirm this action before starting another stage.</div><div class="purchase-order-items">${details}</div>`, 'Check update status'));
    }

    async function saveRecordAction(form, path, payload, label, options = {}) {
        if (state.busy) { return; }
        if (!state.pendingRecordAction) {
            state.pendingRecordAction = {path, payload: JSON.parse(JSON.stringify(payload)), label, uncertain: false, method: options.method || 'POST', recovery_key: uuid(), quiet: Boolean(options.quiet), context: options.context || null};
            try { sessionStorage.setItem(recordStorageKey(), JSON.stringify(state.pendingRecordAction)); } catch { /* Preserve in memory if storage is unavailable. */ }
        }
        const protectedRequest = state.pendingRecordAction;
        state.busy = true;
        const submit = form.querySelector('button[type="submit"]'); let previous = submit.innerHTML;
        const error = form.querySelector('.form-error'); error.hidden = true; submit.disabled = true; submit.textContent = 'Saving…';
        try {
            const result = await api(protectedRequest.path, protectedRequest.method || 'POST', protectedRequest.payload);
            clearRecordAction(); state.activity = null; invalidateReports(); modal.close();
            try { await refresh(); } catch { toast('Saved. Refresh to load the latest stock.', true); }
            if (!protectedRequest.quiet) { toast(protectedRequest.path === '/purchase-orders' ? 'Purchase order created. Stock is added when you record delivery.' : protectedRequest.path.startsWith('/cash-sessions/') ? `${protectedRequest.label} recorded.` : `${protectedRequest.label} recorded. Stock and history are updated.`); }
            return result;
        } catch (failure) {
            const terminalAction = protectedRequest.path.match(/^\/commerce\/orders\/(\d+)\/(import|cancel)$/);
            if (failure.status === 409 && terminalAction && protectedRequest.uncertain) {
                try {
                    const latest = await api('/commerce');
                    const confirmed = latest.orders.find(order => number(order.id) === number(terminalAction[1]));
                    const opposite = terminalAction[2] === 'import' ? 'canceled' : 'imported';
                    if (confirmed?.status === opposite) {
                        clearRecordAction(); state.commerce = latest;
                        selectCommerceContext(confirmed.channel_id, confirmed.location_id);
                        failure.message = opposite === 'canceled' ? 'This order is confirmed canceled. Its reservation is released and it cannot be imported. No additional stock deduction was made.' : 'This order is confirmed imported. It cannot be canceled. Use its sale receipt in Reports for a return.';
                        error.textContent = failure.message; error.hidden = false; renderSection(); return null;
                    }
                } catch { /* Keep the original request protected when the final state cannot be verified. */ }
            }
            const stockAction = protectedRequest.path === '/transfers' || /^\/products\/\d+\/adjust$/.test(protectedRequest.path);
            if ((!stockAction && !protectedRequest.path.startsWith('/commerce/') && !protectedRequest.path.startsWith('/product-families') && failure.status === 422) || (!protectedRequest.uncertain && failure.status >= 400 && failure.status < 500)) {
                clearRecordAction();
                if (['adjust', 'transfer'].includes(form.dataset.form)) {
                    form.dataset.key = uuid();
                    form.querySelectorAll('input,select,button[data-action]:not([data-action="close-modal"])').forEach(input => { input.disabled = false; });
                    previous = `${form.dataset.form === 'adjust' ? 'Update stock' : 'Transfer stock'}`;
                }
            }
            else {
                state.pendingRecordAction.uncertain = true;
                try { sessionStorage.setItem(recordStorageKey(), JSON.stringify(state.pendingRecordAction)); } catch { /* In-memory recovery remains available. */ }
                failure.message = 'We could not confirm this workspace action. The original request is protected. Check update status before starting another stage or changing this request.';
                form.querySelectorAll('input,select,button[data-action]:not([data-action="close-modal"])').forEach(input => { input.disabled = true; });
            }
            error.textContent = failure.message; error.hidden = false; renderSection();
        } finally { state.busy = false; if (submit.isConnected) { submit.disabled = false; submit.innerHTML = state.pendingRecordAction ? 'Check update status' : previous; } }
    }

    function inspectProduct(product) {
        openModal(escape(product.name), `${escape(product.sku)} · ${escape(product.barcode || 'No barcode')}`, `<div class="purchase-order-items">${locations().map(location => `<div class="receipt-line"><strong>${escape(location.name)}</strong><span>${stock(product, location.id)} physical · ${reservedStock(product, location.id)} reserved · ${availableStock(product, location.id)} available</span></div>`).join('')}</div><p class="inline-subtitle">${escape(product.category || 'Uncategorized')} · ${escape(product.variant || 'Standard')} · ${money(product.price)}</p><div class="form-actions">${button('Close', 'close-modal', 'primary')}</div>`);
    }

    function addTeamModal() {
        openModal('Add team member', 'Create a login for someone in this business.', modalForm('team', `<div class="form-grid">${field('Full name', 'name', '', 'text', 'required maxlength="100"', '', true)}${field('Email address', 'email', '', 'email', 'required maxlength="100"', '', true)}${field('Initial password', 'password', '', 'password', 'required minlength="10" autocomplete="new-password"', 'At least 10 characters. Share it securely yourself.', true)}${selectField('Permission level', 'role', '<option value="cashier">Cashier — checkout and own sales</option><option value="manager">Manager — sales, stock and purchasing</option><option value="admin">Administrator — all workspace access</option>', 'Access is enforced on the server.', true)}</div>`, 'Add team member'));
    }

    function editTeamModal(member) {
        const self = number(member.id) === number(state.data.user.id);
        const roles = {cashier: 'Cashier — checkout and own sales', manager: 'Manager — sales, stock and purchasing', admin: 'Administrator — all workspace access'};
        const roleField = self ? `<div class="field full"><label for="member-own-role">Permission level</label><input id="member-own-role" value="Administrator" disabled><small>Another administrator must change your own role or pause your access.</small></div>` : selectField('Permission level', 'role', Object.entries(roles).map(([value, label]) => `<option value="${value}" ${member.role === value ? 'selected' : ''}>${label}</option>`).join(''), 'The new role applies to the member’s next workspace request.', true);
        openModal('Edit team member', escape(member.email), modalForm('edit-team', `<div class="form-grid">${field('Full name', 'name', member.name, 'text', 'required maxlength="100"', '', true)}${roleField}</div>`, 'Save team member', `data-id="${number(member.id)}"`));
    }

    function teamStatusModal(member) {
        const active = member.is_active === false;
        openModal(active ? 'Reactivate team access?' : 'Pause team access?', escape(member.name), modalForm('team-status', `<p class="danger-copy">${active ? `<strong>${escape(member.name)}</strong> will be able to sign in and use the workspace again with their <strong>${escape(member.role)}</strong> permissions.` : `<strong>${escape(member.name)}</strong> will lose access to this workspace. Their sales and activity records stay available. You can reactivate their access later.`}</p><div class="modal-tip" style="margin-top:18px">${active ? 'This restores access. It does not reset their password.' : 'Existing workspace requests will be denied and remembered sign-ins are revoked. Keep at least one active administrator.'}</div>`, active ? 'Reactivate access' : 'Pause access', `data-id="${number(member.id)}" data-active="${active}"`));
    }

    function addLocationModal() {
        openModal('Add stock location', 'Track stock separately in a store, warehouse or online channel.', modalForm('location', `<div class="form-grid">${field('Location name', 'name', '', 'text', 'required maxlength="100"', '', true)}${selectField('Location type', 'type', '<option value="store">Retail store</option><option value="warehouse">Warehouse</option><option value="online">Online channel</option>', '', true)}${field('Address or notes', 'address', '', 'text', 'maxlength="240"', '', true)}</div>`, 'Add location'));
    }

    function locationsModal() {
        openModal('Stock locations', 'Each location has its own stock balance.', `<div class="purchase-order-items">${locations().map(location => `<div class="receipt-line"><strong>${escape(location.name)}</strong><span>${escape(location.type || 'Store')}</span></div>`).join('')}</div><div class="modal-tip">This is a stock location. In Channels, link a local web store, marketplace or social shop to its shared stock. External storefronts are not connected in this simulation.</div><div class="form-actions">${button('Close', 'close-modal')}${button('Add location', 'add-location', 'primary', 'plus')}</div>`);
    }

    function rememberPendingSale(payload, totals) {
        state.pendingSale = JSON.parse(JSON.stringify(payload));
        state.pendingSaleTotals = {...totals};
        state.pendingSaleUncertain = false;
        try { sessionStorage.setItem('stockroom-pending-sale', JSON.stringify({tenantId: state.data.tenant.id, userId: state.data.user.id, payload: state.pendingSale, totals: state.pendingSaleTotals})); } catch { /* Keep the request in memory when browser storage is unavailable. */ }
    }

    function clearPendingSale() {
        state.pendingSale = null;
        state.pendingSaleTotals = null;
        state.pendingSaleUncertain = false;
        try { sessionStorage.removeItem('stockroom-pending-sale'); } catch { /* Browser storage may be unavailable. */ }
    }

    async function completePayment(form, payload) {
        if (!state.pendingSale) {
            payload.idempotency_key ||= window.crypto?.randomUUID ? crypto.randomUUID() : `sale-${Date.now()}-${Math.random().toString(36).slice(2)}`;
            state.saleKey = payload.idempotency_key;
            rememberPendingSale(payload, cartTotals());
        }
        const result = await save(form, '/sales', 'POST', state.pendingSale, '');
        if (result) {
            clearPendingSale();
            state.cart = []; state.discount = 0; state.customer = ''; state.saleKey = null;
            renderSection();
            receiptModal(result.sale || result, true);
        }
    }

    async function save(form, path, method, payload, message) {
        if (state.busy) { return null; }
        state.busy = true;
        const submit = form.querySelector('button[type="submit"]');
        const previous = submit?.innerHTML;
        const settingsControls = form.dataset.form === 'settings' ? Array.from(form.elements, control => ({control, disabled: control.disabled})) : [];
        settingsControls.forEach(({control}) => { control.disabled = true; });
        const error = form.querySelector('.form-error');
        if (error) { error.hidden = true; }
        if (submit) { submit.disabled = true; submit.textContent = 'Saving…'; }
        try {
            const result = await api(path, method, payload);
            state.activity = null; invalidateReports();
            if (form.dataset.form === 'settings') { state.data.settings = result; state.settingsDirty = false; }
            modal.close();
            if (message) { toast(message); }
            try {
                await refresh(form.dataset.form !== 'settings');
                if (form.dataset.form === 'settings') { renderSection(); }
            } catch (refreshError) { if (form.dataset.form === 'settings') { renderSection(); } toast('Saved successfully. Refresh the page to load the latest records.', true); }
            return result;
        } catch (failure) {
            if (form.dataset.form === 'payment') {
                const definitiveRejection = failure.status === 422 || (!state.pendingSaleUncertain && failure.status >= 400 && failure.status < 500 && failure.status !== 409);
                if (definitiveRejection) {
                    clearPendingSale();
                    state.saleKey = null;
                    renderSection();
                    if (form.dataset.recovery) { failure.message = `No sale was saved. ${failure.message} Close this dialog, review the cart and try checkout again.`; }
                } else {
                    state.pendingSaleUncertain = true;
                    failure.message = 'We couldn’t confirm whether the sale was saved. Your original cart and payment are protected. Retry to check the same sale before starting another checkout.';
                    form.querySelectorAll('.payment-tabs button, .payment-details input').forEach(input => { input.disabled = true; });
                    renderSection();
                }
            }
            if (error && error.isConnected) { error.textContent = failure.message; error.hidden = false; }
            else { toast(failure.message, true); }
            return null;
        } finally {
            state.busy = false;
            settingsControls.forEach(({control, disabled}) => { if (control.isConnected) { control.disabled = disabled; } });
            if (submit?.isConnected) { submit.disabled = Boolean(form.dataset.recovery && !state.pendingSale); submit.innerHTML = form.dataset.form === 'payment' && state.pendingSale ? `Check sale status ${icon('refresh')}` : previous; }
        }
    }

    function receiptPrintDocument(sale) {
        return `<!DOCTYPE html><html><head><title>${escape(sale.number || 'Receipt')}</title><style>@page{size:auto;margin:4mm}@media screen{body{padding:4mm!important;box-sizing:border-box}}body{font-family:monospace;font-size:11px;color:#111;margin:0}h3{font-size:20px;text-align:center;margin:0 0 8px;overflow-wrap:anywhere}.receipt-logo{display:block;width:auto;height:auto;max-width:40mm;max-height:24mm;margin:0 auto 10px;object-fit:contain}.receipt-business-detail{text-align:center;font-size:10px;line-height:1.5;overflow-wrap:anywhere}.receipt-address{white-space:pre-line}.receipt-meta{text-align:center;font-size:9px;line-height:1.5;margin-top:10px}.receipt-line{display:flex;justify-content:space-between;gap:10px;margin:9px 0}.receipt-line>*{min-width:0;overflow-wrap:anywhere}.receipt-line>span:last-child{white-space:nowrap}.receipt-items{border-top:1px dashed #222;border-bottom:1px dashed #222;margin:10px 0;padding:8px 0}.receipt-item{margin:10px 0}.receipt-item .receipt-line{margin:3px 0}small{font-size:9px}.receipt-line.total{font-size:14px;font-weight:bold;border-top:1px dashed #222;padding-top:9px}p{text-align:center;font-size:9px;margin-top:20px}.receipt-paper{max-width:72mm;margin:0 auto}</style></head><body>${receiptHtml(sale)}</body></html>`;
    }

    function previewReceiptPrint() {
        if (!state.lastSale) { return; }
        openModal('Receipt print layout', escape(state.lastSale.number || `Sale #${state.lastSale.id}`), `<div class="receipt-print-sheet"><iframe class="receipt-print-preview" title="Receipt print layout" srcdoc="${escape(receiptPrintDocument(state.lastSale))}"></iframe></div><p class="receipt-print-note">This is the same document sent to your printer. For an 80 mm receipt printer, select 80 mm paper and check the scale in the print dialog.</p><div class="form-actions receipt-actions">${button('Back to receipt', 'back-to-receipt', 'subtle')}${button('Print receipt', 'print-receipt', 'primary', 'print')}</div>`);
        const frame = modalContent.querySelector('.receipt-print-preview');
        frame.addEventListener('load', () => { if (frame.isConnected) { frame.style.height = `${frame.contentDocument.body.scrollHeight + 8}px`; } }, {once: true});
    }

    async function printReceipt() {
        if (!state.lastSale || state.receiptPrinting) { return; }
        state.receiptPrinting = true;
        const control = modalContent.querySelector('[data-action="print-receipt"]');
        if (control) { control.disabled = true; }
        let frame;
        let timeout;
        try {
            frame = document.createElement('iframe');
            frame.style.cssText = 'position:fixed;width:0;height:0;border:0;left:-9999px';
            frame.title = 'Printable receipt';
            document.body.append(frame);
            const receiptDocument = frame.contentWindow.document;
            receiptDocument.open();
            receiptDocument.write(receiptPrintDocument(state.lastSale));
            receiptDocument.close();
            await Promise.race([
                Promise.all(Array.from(receiptDocument.images, image => image.decode())),
                new Promise((resolve, reject) => { timeout = setTimeout(() => reject(new Error('Receipt logo did not load.')), 8000); }),
            ]);
            frame.contentWindow.focus();
            frame.contentWindow.print();
            setTimeout(() => frame.remove(), 60000);
        } catch {
            frame?.remove();
            toast('The receipt logo could not load. Check your connection and try printing again.', true);
        } finally {
            clearTimeout(timeout);
            state.receiptPrinting = false;
            modalContent.querySelectorAll('[data-action="print-receipt"]').forEach(currentControl => { currentControl.disabled = false; });
        }
    }

    async function exportSales(control) {
        if (!isAdmin()) { return; }
        control.disabled = true;
        try {
            const filters = state.section === 'reports' && state.analyticsFilters ? state.analyticsFilters : {location_id: String(state.location), source: 'all'};
            const response = await fetch(`/api/reports/export?${analyticsQuery(filters, false)}`, {credentials: 'same-origin', headers: {'Accept': 'text/csv'}});
            if (!response.ok) { throw new Error('Your sales export could not be downloaded. Please sign in or try again.'); }
            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            link.download = `posonly-sales-${filters.location_id ? `location-${filters.location_id}` : 'all-locations'}-${manilaDay(new Date())}.csv`;
            document.body.append(link);
            link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast('Your full matching sales history CSV is ready.');
        } catch (failure) { toast(failure.message, true); }
        finally { if (control.isConnected) { control.disabled = false; } }
    }

    async function exportInvoices(control) {
        if (!isAdmin()) { return; }
        control.disabled = true;
        try {
            const response = await fetch('/api/billing/invoices/export', {credentials: 'same-origin', headers: {'Accept': 'text/csv'}});
            if (!response.ok) { throw new Error('Your invoice export could not be downloaded. Please sign in or try again.'); }
            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a'); link.href = url; link.download = `posonly-billing-${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast('Your full invoice history CSV is ready.');
        } catch (failure) { toast(failure.message, true); }
        finally { if (control.isConnected) { control.disabled = false; } }
    }

    async function exportFinancial(control) {
        if (!isAdmin() || !state.financialFilters) { return; }
        control.disabled = true;
        const filters = {...state.financialFilters};
        try {
            const response = await fetch(`/api/reports/financial/export?${financialQuery(filters)}`, {credentials: 'same-origin', headers: {'Accept': 'text/csv'}});
            if (!response.ok) { throw new Error('Your financial export could not be downloaded. Please sign in or try again.'); }
            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a'); link.href = url; link.download = `posonly-financial-${filters.basis}-${filters.location_id ? `location-${filters.location_id}` : 'all-locations'}-${manilaDay(new Date())}.csv`;
            document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast('Your financial CSV is ready with the last applied filters and report basis.');
        } catch (failure) { toast(failure.message, true); }
        finally { if (control.isConnected) { control.disabled = false; } }
    }

    document.addEventListener('click', async event => {
        const nav = event.target.closest('a[data-section]');
        if (nav && !event.metaKey && !event.ctrlKey) { event.preventDefault(); navigate(nav.dataset.section); return; }
        const control = event.target.closest('[data-action]');
        if (!control || control.disabled) { return; }
        const action = control.dataset.action;
        if (state.pendingSale && ['add-cart', 'cart-minus', 'clear-cart', 'confirm-clear-cart', 'customer-sale', 'category', 'hold-cart', 'resume-held', 'confirm-resume-held', 'delete-held', 'confirm-delete-held'].includes(action)) {
            toast('Confirm the pending checkout before changing this cart.', true);
            paymentModal();
            return;
        }
        if (!canWrite() && ['commerce-add', 'commerce-order', 'commerce-sync', 'add-product', 'edit-product', 'add-variants', 'create-variants', 'append-variants', 'adjust', 'transfer', 'create-order', 'receive-order', 'approve-order', 'generate-reorders', 'add-reorder-rule', 'add-location'].includes(action)) { toast('Sales and stock changes are paused. Review Billing & plans to renew the workspace.', true); return; }
        const id = control.dataset.id;
        const product = productById(id);
        switch (action) {
            case 'navigate': navigate(control.dataset.target); break;
            case 'report-tab': selectReportView(control.dataset.tab); break;
            case 'close-modal': if (!state.busy) { modal.close(); } break;
            case 'category': state.category = control.dataset.category; renderSection(); break;
            case 'add-product': productForm(); break;
            case 'edit-product': if (product) { productForm(product); } break;
            case 'add-variants': variantBuilder(); break;
            case 'create-variants': if (product) { variantBuilder(product); } break;
            case 'manage-variants': manageVariants(productFamilyById(control.dataset.familyId)); break;
            case 'append-variants': {
                const family = productFamilyById(control.dataset.familyId);
                if (family) { variantBuilder(null, family); } else { toast('Refresh the workspace to load this variant family.', true); }
                break;
            }
            case 'generate-variants': generateVariantRows(control.closest('form')); break;
            case 'remove-variant-row': {
                const form = control.closest('form'); control.closest('.variant-matrix-row').remove(); updateVariantRowCount(form); break;
            }
            case 'adjust': if (product) { adjustModal(product); } break;
            case 'transfer': if (product) { transferModal(product); } break;
            case 'inspect-product': if (product) { inspectProduct(product); } break;
            case 'add-customer': addCustomerModal(); break;
            case 'customer-sale': state.customer = id; navigate('pos'); toast('Customer added to your next sale.'); break;
            case 'add-cart': {
                if (!product) { break; }
                if (!canWrite()) { toast('Sales are paused. Ask an administrator to review Billing & plans.', true); break; }
                if (!hasTierPrice(product)) { toast('This product has no wholesale price. Add its price in Inventory first.', true); break; }
                const item = state.cart.find(item => item.product_id === number(id));
                if (availableStock(product) <= number(item?.quantity)) { toast('No more unreserved stock is available at this location.', true); break; }
                if (item) { item.quantity += 1; } else { state.cart.push({product_id: number(id), quantity: 1}); }
                state.saleKey = null;
                renderSection(true);
                break;
            }
            case 'cart-minus': {
                const item = state.cart.find(item => item.product_id === number(id));
                if (item) { item.quantity -= 1; state.cart = state.cart.filter(item => item.quantity > 0); }
                state.saleKey = null;
                renderSection();
                break;
            }
            case 'clear-cart':
                openModal('Clear current cart?', 'Your current items haven’t been sold or deducted from stock.', `<p class="danger-copy">This clears the ${itemsLabel(state.cart.reduce((sum, item) => sum + item.quantity, 0))} in your current cart.</p><div class="form-actions">${button('Keep cart', 'close-modal')}${button('Clear cart', 'confirm-clear-cart', 'primary')}</div>`);
                break;
            case 'confirm-clear-cart': state.cart = []; state.discount = 0; state.customer = ''; state.saleKey = null; modal.close(); renderSection(); break;
            case 'charge':
                if (state.pendingSale) { paymentModal(); break; }
                control.disabled = true;
                try { await refresh(false); renderSection(); paymentModal(); }
                catch (failure) { toast(failure.message, true); if (control.isConnected) { control.disabled = false; } }
                break;
            case 'commerce-refresh':
                control.disabled = true;
                try { await refresh(false); await loadCommerce(); } catch (failure) { toast(failure.message, true); }
                finally { if (control.isConnected) { control.disabled = false; } } break;
            case 'commerce-add': commerceChannelForm(); break;
            case 'commerce-manage': commerceChannelForm(state.commerce?.channels.find(channel => number(channel.id) === number(id))); break;
            case 'commerce-sync':
            case 'commerce-pause':
            case 'commerce-resume': commerceActionModal(state.commerce?.channels.find(channel => number(channel.id) === number(id)), action); break;
            case 'commerce-order': commerceOrderForm(state.commerce?.channels.find(channel => number(channel.id) === number(id))); break;
            case 'commerce-tab': if (['catalog', 'orders', 'syncs'].includes(control.dataset.tab)) { state.commerceTab = control.dataset.tab; renderSection(); } break;
            case 'commerce-add-line': {
                const form = control.closest('form'); const channel = state.commerce?.channels.find(channel => number(channel.id) === number(form.dataset.id));
                if (form.querySelectorAll('.commerce-order-line').length >= 200) { toast('Keep each order to 200 product lines.', true); break; }
                if (channel) { form.querySelector('#commerce-order-lines').insertAdjacentHTML('beforeend', commerceOrderLine(channel)); } break;
            }
            case 'commerce-remove-line': if (control.closest('form').querySelectorAll('.commerce-order-line').length > 1) { control.closest('.commerce-order-line').remove(); } else { toast('Keep at least one product in the order.'); } break;
            case 'commerce-order-details': commerceOrderDetails(state.commerce?.orders.find(order => number(order.id) === number(id))); break;
            case 'commerce-import':
            case 'commerce-cancel': commerceOrderDetails(state.commerce?.orders.find(order => number(order.id) === number(id)), action); break;
            case 'commerce-sale': await commerceSaleReceipt(id, control); break;
            case 'cash-sessions': await loadCashSessions(); break;
            case 'cash-adjust':
            case 'cash-close': cashActionModal(state.cashSessions?.current, action); break;
            case 'hold-cart': holdCartModal(); break;
            case 'held-carts': heldCartsModal(); break;
            case 'resume-held': await resumeHeldCart(id); break;
            case 'confirm-resume-held': await resumeHeldCart(id, true); break;
            case 'delete-held': {
                const held = state.heldCarts.find(cart => cart.id === id); if (!held) { break; }
                openModal('Delete saved cart?', escape(held.name), `<p class="danger-copy">Its products are still in inventory. This only removes the saved cart from this browser.</p><div class="form-actions">${button('Keep saved cart', 'close-modal')}${button('Delete saved cart', 'confirm-delete-held', 'primary', '', `data-id="${escape(id)}"`)}</div>`); break;
            }
            case 'confirm-delete-held': if (persistHeldCarts(state.heldCarts.filter(cart => cart.id !== id))) { heldCartsModal(); renderSection(); toast('Saved cart removed.'); } break;
            case 'refresh-billing': await loadBilling(); break;
            case 'billing-cycle': state.billingCycle = control.dataset.cycle === 'annual' ? 'annual' : 'monthly'; renderSection(); break;
            case 'billing-plan': billingActionModal(action, control.dataset.plan); break;
            case 'billing-checkout':
            case 'billing-cancel':
            case 'billing-resume': billingActionModal(action); break;
            case 'invoice-details': invoiceModal(state.billing?.invoices.find(invoice => number(invoice.id) === number(id))); break;
            case 'billing-attempt-details': billingAttemptModal(state.billing?.payment_attempts?.find(attempt => number(attempt.id) === number(id))); break;
            case 'billing-payment-start': await paymongoInvoiceAction(number(id), 'checkout'); break;
            case 'billing-payment-recheck': await paymongoInvoiceAction(number(id), 'recheck'); break;
            case 'billing-payment-recover': billingRecoveryModal(number(id)); break;
            case 'record-recovery': recordRecoveryModal(); break;
            case 'fill-remaining': {
                const form = control.closest('form'); form.querySelectorAll('[data-product-id]').forEach(input => { input.value = input.max; });
                if (form.dataset.form === 'return') { updateReturnPreview(form); } break;
            }
            case 'reorder-rules': await loadReorderRules(); break;
            case 'add-reorder-rule': reorderRuleForm(); break;
            case 'edit-reorder-rule': reorderRuleForm(state.reorderRules?.find(rule => number(rule.id) === number(id))); break;
            case 'toggle-reorder-rule': {
                const rule = state.reorderRules?.find(rule => number(rule.id) === number(id)); if (!rule) { break; }
                openModal(rule.enabled ? 'Pause future replenishment?' : 'Enable this replenishment rule?', escape(productById(rule.product_id)?.name), modalForm('toggle-reorder-rule', `<p class="danger-copy">${rule.enabled ? 'No new drafts will be generated by this rule. Existing purchase orders stay in your records.' : 'This rule can generate new drafts when the product reaches its reorder level.'}</p>`, rule.enabled ? 'Pause rule' : 'Enable rule', `data-id="${rule.id}" data-enabled="${!rule.enabled}"`)); break;
            }
            case 'generate-reorders': openModal('Generate replenishment drafts', 'Review and approve the generated drafts before receiving deliveries.', modalForm('generate-reorders', '<p class="danger-copy">Check enabled rules across all locations and create drafts for stock that needs replenishment. Outstanding deliveries count toward each target. No order is sent to a supplier.</p>', 'Generate drafts')); break;
            case 'approve-order':
            case 'cancel-order': orderActionModal(state.data.purchase_orders.find(order => number(order.id) === number(id)), action); break;
            case 'view-cart': view.querySelector('.cart-panel').scrollIntoView({behavior: 'smooth', block: 'start'}); break;
            case 'payment-method':
                state.payment = control.dataset.method;
                document.querySelectorAll('.payment-tabs button').forEach(button => { const selected = button.dataset.method === state.payment; button.classList.toggle('active', selected); button.setAttribute('aria-pressed', String(selected)); });
                document.getElementById('payment-details').innerHTML = paymentDetails();
                break;
            case 'receipt': await openSaleRecord(id); break;
            case 'preview-receipt-print': previewReceiptPrint(); break;
            case 'back-to-receipt': if (state.lastSale) { receiptModal(state.lastSale); } break;
            case 'print-receipt': printReceipt(); break;
            case 'return': await openSaleRecord(id, true); break;
            case 'create-order': createOrderModal(); break;
            case 'suppliers': supplierModal(); break;
            case 'add-order-line': document.getElementById('purchase-lines').insertAdjacentHTML('beforeend', orderLine()); break;
            case 'remove-order-line': if (document.querySelectorAll('.purchase-line').length > 1) { control.closest('.purchase-line').remove(); purchaseTotal(); } else { toast('Keep at least one product in your order.'); } break;
            case 'order-details': { const order = state.data.purchase_orders.find(order => number(order.id) === number(id)); if (order) { orderDetails(order); } break; }
            case 'receive-order': { const order = state.data.purchase_orders.find(order => number(order.id) === number(id)); if (order) { orderDetails(order, true); } break; }
            case 'add-team': addTeamModal(); break;
            case 'edit-team': { const member = state.data.team.find(member => number(member.id) === number(id)); if (member) { editTeamModal(member); } break; }
            case 'toggle-team': { const member = state.data.team.find(member => number(member.id) === number(id)); if (member) { teamStatusModal(member); } break; }
            case 'refresh-activity': await loadActivity(); break;
            case 'activity-page': await loadActivity(number(control.dataset.page)); break;
            case 'reset-activity': state.activityFilters = {actor_id: '', action: '', from: '', to: ''}; await loadActivity(1); break;
            case 'add-location': addLocationModal(); break;
            case 'locations': locationsModal(); break;
            case 'export-sales': await exportSales(control); break;
            case 'export-invoices': await exportInvoices(control); break;
            case 'export-financial': await exportFinancial(control); break;
            case 'analytics-refresh': await refreshReports(); break;
            case 'receipt-history-page': await loadReceiptHistory(number(control.dataset.page)); break;
            case 'receipt-history-retry': await loadReceiptHistory(state.receiptHistoryPage); break;
            case 'receipt-history-clear': state.receiptHistorySearch = ''; state.receiptHistoryDraft = ''; state.receiptHistory = null; await loadReceiptHistory(1); break;
            case 'analytics-retry': await loadAnalytics(state.analyticsFilters || initializeReportFilters()); break;
            case 'analytics-reset': state.analyticsDraft = null; await loadAnalytics(initializeReportFilters()); break;
            case 'financial-refresh': await loadFinancial(state.financialFilters || initializeFinancialFilters()); break;
            case 'financial-retry': await loadFinancial(state.financialFilters || initializeFinancialFilters()); break;
            case 'financial-reset': state.financialDraft = null; await loadFinancial(initializeFinancialFilters()); break;
            case 'monitoring-refresh': await loadMonitoring(); break;
            case 'monitoring-probe': await runMonitoringProbe(); break;
            case 'monitoring-acknowledge': await acknowledgeMonitoringIncident(id); break;
            case 'confirm-location':
                state.location = state.pendingLocation; state.pendingLocation = null; state.cart = []; state.discount = 0; state.customer = ''; state.saleKey = null; modal.close(); locationSelect.value = state.location; renderSection();
                document.getElementById('stock-badge').textContent = lowStock().length; document.getElementById('stock-badge').hidden = !lowStock().length || !alertsEnabled();
                break;
            case 'refresh':
                control.disabled = true;
                try { await refresh(); toast('Your workspace is up to date.'); } catch (failure) { toast(failure.message, true); control.disabled = false; }
                break;
            case 'retry': boot(); break;
        }
    });

    document.addEventListener('input', event => {
        const target = event.target;
        if (target.closest('[data-form="receipt-search"]') && target.name === 'receipt') { state.receiptHistoryDraft = target.value; }
        const settingsForm = target.closest('[data-form="settings"]');
        if (settingsForm && target.name !== 'receipt_logo' && target.name !== 'remove_receipt_logo') { updateReceiptSettingsPreview(settingsForm, target); }
        if (target.closest('[data-form="variant-family"]') && target.name === 'name') {
            const form = target.closest('form'); const prefix = form.elements.namedItem('sku_prefix');
            if (prefix.dataset.automatic === 'true') { prefix.value = variantSlug(target.value || 'PRODUCT').slice(0, 40); updateVariantRowCount(form); }
        }
        if (target.closest('[data-form="variant-family"]') && target.name === 'sku_prefix') { target.dataset.automatic = 'false'; }
        if (target.closest('[data-form="variant-family"]') && ['values_size', 'values_color', 'sku_prefix'].includes(target.name)) { updateVariantRowCount(target.closest('form')); }
        if (target.id === 'commerce-search') { state.commerceSearch = target.value; renderSection(true); }
        if (target.id === 'workspace-search') { state.search = target.value; renderSection(true); }
        if (target.id === 'cart-discount') {
            if (state.pendingSale) { target.value = state.discount; return; }
            state.discount = Math.min(100, Math.max(0, number(target.value)));
            state.saleKey = null;
            const focused = target;
            const totals = cartTotals();
            const totalsContainer = view.querySelector('.cart-totals');
            totalsContainer.innerHTML = `<div class="total-row"><span>Subtotal</span><b>${money(totals.subtotal)}</b></div>${totals.discount ? `<div class="total-row"><span>Discount (${state.discount}%)</span><b>−${money(totals.discount)}</b></div>` : ''}<div class="total-row"><span>Tax (${totals.taxPercent}%)</span><b>${money(totals.tax)}</b></div><div class="total-row grand-total"><span>Total due</span><b>${money(totals.total)}</b></div>`;
            view.querySelector('[data-action="charge"] span').textContent = `Charge ${money(totals.total)}`;
            focused.setAttribute('aria-invalid', number(target.value) > 100 || number(target.value) < 0 ? 'true' : 'false');
        }
        if (target.closest('.purchase-line')) { purchaseTotal(); }
        if (target.closest('[data-form="return"]') && target.dataset.productId) { updateReturnPreview(target.closest('form')); }
        if (target.closest('[data-form="payment"]') && /_amount$/.test(target.name)) {
            const form = target.closest('form');
            const amount = ['cash_amount', 'card_amount', 'wallet_amount'].reduce((total, name) => total + number(form.elements.namedItem(name)?.value), 0);
            const changeDisplay = document.querySelector('#change-display b');
            if (changeDisplay) { changeDisplay.textContent = money(Math.max(0, amount - cartTotals().total)); }
        }
    });

    document.addEventListener('change', event => {
        const target = event.target;
        const settingsForm = target.closest('[data-form="settings"]');
        if (settingsForm) { updateReceiptSettingsPreview(settingsForm, target); }
        const reportForm = target.closest('[data-form="analytics-filters"],[data-form="financial-filters"]');
        if (reportForm) {
            const report = reportForm.dataset.form === 'financial-filters' ? 'financial' : 'analytics';
            const draft = report === 'financial' ? initializeFinancialFilters() : initializeReportFilters();
            if (target.name === 'report_preset') {
                state[`${report}Draft`] = {...draft, preset: target.value, ...(target.value !== 'custom' ? reportPresetDates(target.value) : {})};
                renderSection(true);
            }
            if (target.name === 'report_location') { draft.location_id = target.value; }
            if (target.name === 'report_source') { draft.source = target.value; }
            if (target.name === 'report_rank') { draft.rank_by = target.value; }
            if (target.name === 'report_basis') { draft.basis = target.value; }
            if (target.name === 'report_from') { draft.from = target.value; }
            if (target.name === 'report_to') { draft.to = target.value; }
        }
        if (target.id === 'commerce-order-status') { state.commerceOrderStatus = target.value; renderSection(); }
        if (target.id === 'commerce-channel') { state.commerceChannel = number(target.value); state.commerceSearch = ''; renderSection(); }
        if (target.matches('.commerce-order-line select[name="sku"]')) { const quantity = target.closest('.commerce-order-line').querySelector('[name="quantity"]'); quantity.max = Math.min(100000, number(target.selectedOptions[0]?.dataset.available) || 100000); }
        if (target.id === 'category-select') { state.category = target.value; renderSection(); }
        if (target.id === 'family-select') { state.productFamily = target.value; renderSection(); }
        if (target.id === 'cart-customer') { if (state.pendingSale) { target.value = state.customer; return; } state.customer = target.value; state.saleKey = null; }
        if (target.id === 'cart-price-tier') {
            if (state.pendingSale) { target.value = state.priceTier; return; }
            if (target.value === 'wholesale' && state.cart.some(item => productById(item.product_id)?.wholesale_price == null)) { target.value = state.priceTier; toast('Every cart item needs a wholesale price. Set missing prices in Inventory first.', true); return; }
            state.priceTier = target.value === 'wholesale' ? 'wholesale' : 'retail'; state.saleKey = null; renderSection();
        }
        if (target.matches('.purchase-line select[name="product_id"]')) {
            target.closest('.purchase-line').querySelector('[name="cost"]').value = target.selectedOptions[0]?.dataset.cost || '0';
            purchaseTotal();
        }
    });

    document.addEventListener('toggle', event => {
        const details = event.target;
        if (details.matches?.('[data-billing-history]') && details.isConnected) { state.billingHistoryOpen = details.open; return; }
        if (!details.matches?.('[data-financial-breakdown]') || !details.isConnected) { return; }
        const name = details.dataset.financialBreakdown;
        state.financialOpen = state.financialOpen.filter(value => value !== name);
        if (details.open) { state.financialOpen.push(name); }
    }, true);

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !modal.open && document.getElementById('sidebar').classList.contains('open')) {
            event.preventDefault();
            closeSidebar();
            document.getElementById('menu-button').focus();
            return;
        }
        if (event.target.matches('[role="tab"][data-action="report-tab"]') && canManage() && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            selectReportView(event.key === 'Home' ? 'analytics' : event.key === 'End' ? 'financial' : state.reportView === 'analytics' ? 'financial' : 'analytics');
            view.querySelector(`#report-tab-${state.reportView}`)?.focus(); return;
        }
        if (event.target.matches('[role="tab"][data-action="commerce-tab"]') && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            const tabs = ['catalog', 'orders', 'syncs']; const index = tabs.indexOf(state.commerceTab);
            state.commerceTab = tabs[event.key === 'Home' ? 0 : event.key === 'End' ? 2 : (index + (event.key === 'ArrowRight' ? 1 : 2)) % 3];
            renderSection(); view.querySelector(`#commerce-tab-${state.commerceTab}`).focus(); return;
        }
        if (event.target.id === 'workspace-search' && state.section === 'pos' && event.key === 'Enter') {
            event.preventDefault();
            if (state.pendingSale) { paymentModal(); return; }
            const query = state.search.trim();
            const exactMatches = query ? products().filter(product => [product.barcode, product.sku].some(value => value && String(value).trim() === query)) : [];
            const insensitiveMatches = !exactMatches.length && query ? products().filter(product => [product.barcode, product.sku].some(value => value && String(value).trim().toLowerCase() === query.toLowerCase())) : exactMatches;
            if (insensitiveMatches.length > 1) { toast('This code matches more than one SKU. Select the exact product or use a unique barcode.', true); return; }
            const product = insensitiveMatches[0] || (query && filteredProducts().length === 1 ? filteredProducts()[0] : null);
            if (product) {
                if (!canWrite() || !hasTierPrice(product)) { toast(!canWrite() ? 'Sales are paused. Review Billing & plans.' : 'This product has no wholesale price.', true); return; }
                const item = state.cart.find(item => item.product_id === number(product.id));
                if (availableStock(product) <= number(item?.quantity)) { toast('This product has no unreserved stock available.', true); return; }
                if (item) { item.quantity += 1; } else { state.cart.push({product_id: number(product.id), quantity: 1}); }
                state.search = ''; state.saleKey = null; renderSection(); document.getElementById('workspace-search').focus(); toast(`${product.name} added.`);
            } else { toast('No matching barcode. Try the product name or SKU.', true); }
        }
    });

    document.addEventListener('submit', async event => {
        const form = event.target.closest('form[data-form]');
        if (!form) { return; }
        event.preventDefault();
        const data = Object.fromEntries(new FormData(form));
        let result;
        switch (form.dataset.form) {
            case 'financial-filters': {
                const draft = {...initializeFinancialFilters(), preset: data.report_preset, location_id: data.report_location || '', source: data.report_source, basis: data.report_basis};
                Object.assign(draft, draft.preset === 'custom' ? {from: data.report_from || '', to: data.report_to || ''} : reportPresetDates(draft.preset));
                state.financialDraft = draft;
                await loadFinancial(draft); break;
            }
            case 'receipt-search': state.receiptHistorySearch = (data.receipt || '').trim(); state.receiptHistoryDraft = state.receiptHistorySearch; state.receiptHistory = null; await loadReceiptHistory(1); break;
            case 'analytics-filters': {
                const draft = {...initializeReportFilters(), preset: data.report_preset, location_id: data.report_location || '', source: data.report_source, rank_by: data.report_rank};
                Object.assign(draft, draft.preset === 'custom' ? {from: data.report_from || '', to: data.report_to || ''} : reportPresetDates(draft.preset));
                state.analyticsDraft = draft;
                await loadAnalytics(draft); break;
            }
            case 'variant-family': await submitVariantFamily(form, data); break;
            case 'add-product':
            case 'edit-product': {
                const payload = {...data, wholesale_price: data.wholesale_price === '' ? null : number(data.wholesale_price), price: number(data.price), cost: number(data.cost), reorder_level: number(data.reorder_level)};
                const product = productById(form.dataset.id);
                if (product?.product_family_id) {
                    payload.variant_options = Object.fromEntries((product.option_names || []).map(axis => [axis, String(data[`option_${axis}`] || '').trim()]));
                    (product.option_names || []).forEach(axis => { delete payload[`option_${axis}`]; });
                    delete payload.variant;
                }
                if (form.dataset.form === 'add-product') { payload.quantity = number(data.quantity); payload.location_id = state.location; }
                await save(form, form.dataset.form === 'add-product' ? '/products' : `/products/${form.dataset.id}`, form.dataset.form === 'add-product' ? 'POST' : 'PUT', payload, form.dataset.form === 'add-product' ? 'Your product is ready to sell.' : 'Product details updated.');
                break;
            }
            case 'adjust':
            case 'transfer': {
                if (state.pendingRecordAction) { const pending = state.pendingRecordAction; await saveRecordAction(form, pending.path, pending.payload, pending.label); break; }
                const adjustment = form.dataset.form === 'adjust';
                const payload = {idempotency_key: form.dataset.key, quantity: number(data.quantity), ...(adjustment ? {location_id: number(form.dataset.location), reason: data.reason} : {product_id: number(form.dataset.id), from_location_id: number(form.dataset.location), to_location_id: number(data.to_location_id)})};
                await saveRecordAction(form, adjustment ? `/products/${form.dataset.id}/adjust` : '/transfers', payload, adjustment ? 'Stock adjustment' : 'Stock transfer'); break;
            }
            case 'customer':
                result = await save(form, '/customers', 'POST', data, 'Customer added.');
                if (result && state.section === 'pos') { state.customer = String(result.id || result.customer?.id || ''); renderSection(); }
                break;
            case 'payment': {
                if (state.pendingSale) { await completePayment(form, state.pendingSale); break; }
                const total = cartTotals().total;
                let payments;
                if (state.payment === 'split') { payments = [{method: 'cash', amount: round(data.cash_amount)}, {method: 'card', amount: round(data.card_amount)}, {method: 'wallet', amount: round(data.wallet_amount)}].filter(payment => payment.amount > 0); }
                else { payments = [{method: state.payment, amount: state.payment === 'cash' ? round(data.cash_amount) : total}]; }
                const paid = round(payments.reduce((sum, payment) => sum + payment.amount, 0));
                const nonCash = round(payments.filter(payment => payment.method !== 'cash').reduce((sum, payment) => sum + payment.amount, 0));
                if (paid < total || nonCash > total) {
                    const error = form.querySelector('.form-error'); error.textContent = paid < total ? 'The total payment must cover the amount due.' : 'Card and wallet payments cannot exceed the sale total. Change is given from cash.'; error.hidden = false; return;
                }
                await completePayment(form, {location_id: state.location, items: state.cart.map(item => ({...item})), price_tier: state.priceTier, discount_percent: state.discount, tax_percent: cartTotals().taxPercent, customer_id: state.customer ? number(state.customer) : null, payments, idempotency_key: state.saleKey});
                break;
            }
            case 'hold-cart': {
                if (state.pendingSale || !state.cart.length) { break; }
                const record = {id: uuid(), name: data.name.trim(), location_id: state.location, items: state.cart.map(item => ({...item})), discount_percent: state.discount, customer_id: state.customer ? number(state.customer) : null, price_tier: state.priceTier, saved_at: new Date().toISOString()};
                if (!record.name) { const error = form.querySelector('.form-error'); error.textContent = 'Give this saved cart a name.'; error.hidden = false; break; }
                if (persistHeldCarts([record, ...state.heldCarts])) { state.cart = []; state.discount = 0; state.customer = ''; state.saleKey = null; modal.close(); renderSection(); toast('Cart saved in this browser. Stock has not been reserved.'); } break;
            }
            case 'commerce-create': await submitCommerceAction(form, '/commerce/channels', {name: data.name.trim(), kind: data.kind, location_id: number(data.location_id), idempotency_key: form.dataset.key}, 'Channel creation'); break;
            case 'commerce-edit': await submitCommerceAction(form, `/commerce/channels/${form.dataset.id}`, {name: data.name.trim(), simulate_failure: form.elements.simulate_failure.checked}, 'Channel configuration', 'PATCH'); break;
            case 'commerce-sync': await submitCommerceAction(form, `/commerce/channels/${form.dataset.id}/sync`, {idempotency_key: form.dataset.key}, 'Channel sync'); break;
            case 'commerce-pause': await submitCommerceAction(form, `/commerce/channels/${form.dataset.id}/pause`, {}, 'Channel pause'); break;
            case 'commerce-resume': await submitCommerceAction(form, `/commerce/channels/${form.dataset.id}/resume`, {}, 'Channel resume'); break;
            case 'commerce-order': {
                if (state.pendingRecordAction) { const pending = state.pendingRecordAction; const result = await saveRecordAction(form, pending.path, pending.payload, pending.label); if (result) { await finishCommerceAction(result, pending); } break; }
                const items = [...form.querySelectorAll('.commerce-order-line')].map(line => ({sku: line.querySelector('[name="sku"]').value, quantity: number(line.querySelector('[name="quantity"]').value)}));
                if (new Set(items.map(item => item.sku)).size !== items.length) { const error = form.querySelector('.form-error'); error.textContent = 'Each SKU can appear once. Combine its quantity in one line.'; error.hidden = false; break; }
                await submitCommerceAction(form, `/commerce/channels/${form.dataset.id}/orders`, {external_reference: data.external_reference.trim(), idempotency_key: form.dataset.key, items}, 'Simulated order creation'); break;
            }
            case 'commerce-import': await submitCommerceAction(form, `/commerce/orders/${form.dataset.id}/import`, {}, 'Channel order import'); break;
            case 'commerce-cancel': await submitCommerceAction(form, `/commerce/orders/${form.dataset.id}/cancel`, {}, 'Channel order cancellation'); break;
            case 'cash-open':
            case 'cash-adjust':
            case 'cash-close': {
                if (state.pendingRecordAction) { const pending = state.pendingRecordAction; result = await saveRecordAction(form, pending.path, pending.payload, pending.label); if (result) { await loadCashSessions(); } break; }
                const action = form.dataset.form;
                const payload = {idempotency_key: form.dataset.key, ...(action === 'cash-open' ? {location_id: number(form.dataset.location), opening_float: round(data.opening_float)} : action === 'cash-adjust' ? {direction: data.direction, amount: round(data.amount), reason: data.reason} : {counted_cash: round(data.counted_cash), reason: data.reason || null})};
                const path = action === 'cash-open' ? '/cash-sessions/open' : `/cash-sessions/${form.dataset.id}/${action === 'cash-adjust' ? 'adjustments' : 'close'}`;
                result = await saveRecordAction(form, path, payload, action === 'cash-open' ? 'Cash session opening' : action === 'cash-adjust' ? 'Cash movement' : 'Cash session closing');
                if (result) { await loadCashSessions(); } break;
            }
            case 'billing-plan': await saveBilling(form, '/billing/change-plan', {plan: form.dataset.plan, billing_cycle: form.dataset.cycle}); break;
            case 'billing-checkout': await saveBilling(form, '/billing/checkout'); break;
            case 'billing-cancel': await saveBilling(form, '/billing/cancel'); break;
            case 'billing-resume': await saveBilling(form, '/billing/resume'); break;
            case 'billing-payment-recover': await paymongoInvoiceAction(number(form.dataset.id), 'recover', {session_id: data.session_id}, form); break;
            case 'simulate-payment': await saveBilling(form, `/billing/invoices/${form.dataset.id}/simulate-payment`, {idempotency_key: form.dataset.key}); break;
            case 'purchase': {
                if (state.pendingRecordAction) { recordRecoveryModal(); break; }
                const items = [...form.querySelectorAll('.purchase-line')].map(line => ({product_id: number(line.querySelector('[name="product_id"]').value), quantity: number(line.querySelector('[name="quantity"]').value), cost: number(line.querySelector('[name="cost"]').value)}));
                if (new Set(items.map(item => item.product_id)).size !== items.length) { const error = form.querySelector('.form-error'); error.textContent = 'Each product can appear once. Combine its quantity in one line.'; error.hidden = false; return; }
                await saveRecordAction(form, '/purchase-orders', {supplier_id: number(data.supplier_id), location_id: number(data.location_id), status: data.status || 'ordered', items, idempotency_key: form.dataset.key}, 'Purchase order creation');
                break;
            }
            case 'supplier': result = await save(form, '/suppliers', 'POST', data, 'Supplier added.'); if (result && form.dataset.next === 'purchase') { createOrderModal(); } break;
            case 'receive':
            case 'return': {
                const items = [...form.querySelectorAll('[data-product-id]')].map(input => ({product_id: number(input.dataset.productId), quantity: number(input.value)})).filter(item => item.quantity > 0);
                if (!items.length && !state.pendingRecordAction) { const error = form.querySelector('.form-error'); error.textContent = 'Enter at least one quantity greater than zero.'; error.hidden = false; break; }
                const returning = form.dataset.form === 'return';
                result = await saveRecordAction(form, returning ? `/sales/${form.dataset.id}/return` : `/purchase-orders/${form.dataset.id}/receive`, {items, idempotency_key: form.dataset.key, ...(returning ? {reason: data.reason} : {note: data.note || null})}, returning ? 'Return' : 'Delivery');
                if (result && returning) { receiptModal(result.sale || result); } break;
            }
            case 'record-recovery': {
                if (!state.pendingRecordAction) { break; }
                const pending = state.pendingRecordAction; result = await saveRecordAction(form, pending.path, pending.payload, pending.label);
                if (result && pending.path.startsWith('/sales/')) { receiptModal(result.sale || result); }
                if (result && pending.path.startsWith('/cash-sessions/')) { await loadCashSessions(); }
                if (result && pending.path.startsWith('/commerce/')) { await finishCommerceAction(result, pending); }
                if (result && pending.path.startsWith('/product-families')) { finishVariantAction(result); } break;
            }
            case 'approve-order': await save(form, `/purchase-orders/${form.dataset.id}/approve`, 'POST', {}, 'Purchase order approved. Receive stock after delivery.'); break;
            case 'cancel-order': await save(form, `/purchase-orders/${form.dataset.id}/cancel`, 'POST', {}, 'Outstanding order canceled. Previously received stock is unchanged.'); break;
            case 'reorder-rule': {
                const payload = {target_quantity: number(data.target_quantity), enabled: form.elements.enabled.checked, ...(!form.dataset.id ? {product_id: number(data.product_id), location_id: number(data.location_id), supplier_id: number(data.supplier_id)} : {})};
                result = await save(form, form.dataset.id ? `/reorder-rules/${form.dataset.id}` : '/reorder-rules', form.dataset.id ? 'PATCH' : 'POST', payload, 'Replenishment rule saved.'); if (result) { await loadReorderRules(); } break;
            }
            case 'toggle-reorder-rule': result = await save(form, `/reorder-rules/${form.dataset.id}`, 'PATCH', {enabled: form.dataset.enabled === 'true'}, 'Replenishment rule updated.'); if (result) { await loadReorderRules(); } break;
            case 'generate-reorders': {
                result = await save(form, '/reorder-rules/generate', 'POST', {}, '');
                if (result) { toast(result.created_count ? `${count(result.created_count)} purchase ${number(result.created_count) === 1 ? 'draft is' : 'drafts are'} ready to review.` : 'No restock drafts needed. Available stock and outstanding purchase orders cover your targets.'); } break;
            }
            case 'team': await save(form, '/team', 'POST', data, 'Team member added. Share their login details securely.'); break;
            case 'edit-team': await save(form, `/team/${form.dataset.id}`, 'PATCH', {name: data.name, ...(data.role ? {role: data.role} : {})}, 'Team details and permissions updated.'); break;
            case 'team-status': await save(form, `/team/${form.dataset.id}`, 'PATCH', {is_active: form.dataset.active === 'true'}, form.dataset.active === 'true' ? 'Team access reactivated.' : 'Team access paused. Their records are preserved.'); break;
            case 'activity-filters': state.activityFilters = {actor_id: data.actor_id || '', action: data.action || '', from: data.from || '', to: data.to || ''}; await loadActivity(1); break;
            case 'location': await save(form, '/locations', 'POST', data, 'Location added. You can now transfer stock there.'); break;
            case 'settings': {
                const payload = new FormData();
                payload.set('_method', 'PUT');
                ['tax_percent', 'receipt_footer', 'receipt_address', 'receipt_phone'].forEach(name => payload.set(name, form.elements.namedItem(name).value));
                payload.set('low_stock_alerts', form.elements.low_stock_alerts.checked ? '1' : '0');
                payload.set('remove_receipt_logo', form.elements.remove_receipt_logo.checked ? '1' : '0');
                if (form.elements.receipt_logo.files[0]) { payload.set('receipt_logo', form.elements.receipt_logo.files[0]); }
                await save(form, '/settings', 'POST', payload, 'Workspace settings saved. Receipt details are ready.'); break;
            }
        }
    });

    window.addEventListener('storage', event => { if (event.key === state.heldOwnerKey) { state.heldOwnerKey = null; loadHeldCarts(); if (state.section === 'pos' && !modal.open && !state.busy) { renderSection(true); } } });

    locationSelect.addEventListener('change', () => {
        if (state.pendingSale) { locationSelect.value = state.location; toast('Confirm the pending checkout before switching locations.', true); paymentModal(); return; }
        const selected = number(locationSelect.value);
        if (state.cart.length) {
            state.pendingLocation = selected;
            locationSelect.value = state.location;
            openModal('Move to another location?', 'A checkout uses stock from one location.', `<p class="danger-copy">Switching to <strong>${escape(locationName(selected))}</strong> will clear your current cart. Your current items have not been sold.</p><div class="form-actions">${button('Keep this location', 'close-modal')}${button('Switch & clear cart', 'confirm-location', 'primary')}</div>`);
        } else {
            state.location = selected;
            if (!(state.section === 'settings' && state.settingsDirty)) { renderSection(); }
            const badge = document.getElementById('stock-badge'); badge.textContent = lowStock().length; badge.hidden = !lowStock().length || !alertsEnabled();
        }
    });
    document.getElementById('menu-button').addEventListener('click', () => {
        const open = document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebar-shade').classList.toggle('open', open);
        document.getElementById('menu-button').setAttribute('aria-expanded', String(open));
    });
    document.getElementById('sidebar-shade').addEventListener('click', closeSidebar);
    modal.addEventListener('click', event => { if (event.target === modal && !state.busy) { modal.close(); } });
    modal.addEventListener('close', () => { state.saleRecordRequest += 1; });
    modal.addEventListener('cancel', event => { if (state.busy) { event.preventDefault(); } });
    window.addEventListener('popstate', () => { state.saleRecordRequest += 1; const section = location.pathname.split('/')[2] || 'overview'; state.section = Object.hasOwn(sections, section) ? section : 'overview'; if (state.section === 'reports') { state.reportView = new URLSearchParams(location.search).get('view') === 'financial' && canManage() ? 'financial' : 'analytics'; } state.search = ''; state.category = 'All products'; renderSection(); });
    window.addEventListener('offline', () => { document.getElementById('connection-label').textContent = 'Offline · reconnect before saving'; document.querySelector('.workspace-status').classList.add('offline'); toast('You are offline. Reconnect before completing a sale.', true); });
    window.addEventListener('online', () => { document.getElementById('connection-label').textContent = 'Reconnected · refresh to sync'; document.querySelector('.workspace-status').classList.remove('offline'); });

    setInterval(async () => {
        if (!state.data || document.hidden || modal.open || state.busy || state.refreshing || state.pendingSale || (state.section === 'settings' && state.settingsDirty) || document.activeElement?.matches('input,select,textarea')) { return; }
        try {
            if (state.section === 'monitoring') {
                if (!state.monitoringLoading && !state.monitoringBusy) { await loadMonitoring(true); }
            } else if (state.section === 'billing') {
                if (!state.billingLoading && !state.billingBusy) { await loadBilling(true); }
            } else if (state.section === 'reports' && state.reportView === 'financial') {
                if (!state.financialLoading) { await loadFinancial(state.financialFilters || initializeFinancialFilters(), true); }
            } else {
                await refresh();
                if (state.section === 'channels') { await loadCommerce(); }
                if (state.section === 'reports' && state.reportView === 'analytics' && !state.analyticsLoading) { await loadAnalytics(state.analyticsFilters || initializeReportFilters(), true); }
            }
        }
        catch { document.getElementById('connection-label').textContent = 'Sync paused · check your connection'; document.querySelector('.workspace-status').classList.add('offline'); }
    }, 20000);

    setInterval(updateMonitoringControls, 1000);

    async function boot() {
        try { await refresh(); }
        catch (failure) {
            view.innerHTML = `${heading('Workspace unavailable', 'Try again or sign in to reload your workspace.', '', '', 'workspace-heading')}${empty('The workspace couldn’t load.', escape(failure.message), button('Try again', 'retry', 'primary', 'refresh') + '<a href="/login" class="button" style="margin-left:8px">Sign in</a>', false, 'alert')}`;
            document.getElementById('connection-label').textContent = 'Connection needs attention';
            document.querySelector('.workspace-status').classList.add('offline');
        }
    }
    boot();
})();
