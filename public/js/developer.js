(() => {
    'use strict';

    const sections = {overview: 'Overview', companies: 'Companies', billing: 'Subscriptions & invoices', content: 'Website content', activity: 'Platform activity'};
    const icons = {
        refresh: '<path d="M20 7v5h-5M4 17v-5h5M6.1 7a7 7 0 0 1 11.6-2L20 8M4 16l2.3 3a7 7 0 0 0 11.6-2"/>',
        company: '<path d="M3 21V7l9-4v18M12 10h9v11M2 21h20M6 9h2M6 13h2M6 17h2M15 14h3M15 18h3"/>',
        card: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 9h20M6 15h4M16 15h2"/>',
        document: '<path d="M6 3h9l4 4v14H6zM14 3v5h5M9 12h7M9 16h5"/>',
        globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18"/>',
        activity: '<path d="M6 3h12v18H6zM9 7h6M9 11h6M9 15h3"/>',
        close: '<path d="m6 6 12 12M6 18 18 6"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        arrow: '<path d="M5 12h14M14 7l5 5-5 5"/>',
        back: '<path d="M19 12H5M10 7l-5 5 5 5"/>',
        pause: '<path d="M8 4v16M16 4v16"/>',
        check: '<path d="m5 12 4 4 10-10"/>'
    };
    const view = document.querySelector('#developer-view');
    const modal = document.querySelector('#developer-modal');
    const modalContent = document.querySelector('#developer-modal-content');
    if (!view || !modal) return;

    const initialSection = document.body.dataset.developerSection;
    const state = {
        section: sections[initialSection] ? initialSection : 'overview',
        identity: null, plans: [], paymentConfig: null,
        lists: {}, companyId: null, companyData: null, companyLoading: false, companyError: '', companyRequest: 0,
        companyTab: 'overview', companyPage: 1, actionBusy: false,
        content: {data: null, draft: null, dirty: false, loading: false, error: '', request: 0},
        modalBusy: false, modalAction: null
    };
    Object.keys(sections).forEach(section => {
        state.lists[section] = {data: null, filters: {search: '', status: 'all', page: 1, tenant_id: ''}, drafts: {search: '', status: 'all'}, loading: false, error: '', request: 0};
    });

    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const svg = key => `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[key] || icons.document}</svg>`;
    const money = value => value == null ? '—' : new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP', maximumFractionDigits: 2}).format(Number(value));
    const count = value => value == null ? '—' : new Intl.NumberFormat('en-PH').format(Number(value));
    const plural = (value, singular, multiple = `${singular}s`) => `${count(value)} ${Number(value) === 1 ? singular : multiple}`;
    const human = value => String(value ?? '').replace(/_/g, ' ').replace(/^./, c => c.toUpperCase());
    function date(value, withTime = false) {
        if (!value) return '—';
        const parsed = new Date(value);
        if (!Number.isFinite(parsed.getTime())) return '—';
        return new Intl.DateTimeFormat('en-PH', {timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: 'numeric', ...(withTime ? {hour: 'numeric', minute: '2-digit'} : {})}).format(parsed);
    }
    function button(label, action, id = '', style = '', icon = '') {
        return `<button class="button ${style}" type="button" data-action="${escape(action)}"${id !== '' ? ` data-id="${escape(id)}"` : ''}>${icon ? svg(icon) : ''}${escape(label)}</button>`;
    }
    function badge(label, color = '') { return `<span class="badge ${color}">${escape(label)}</span>`; }
    function statusBadge(status) {
        const labels = {trialing: 'Trial', active: 'Active', past_due: 'Past due', canceled: 'Canceled', open: 'Open', paid: 'Paid', void: 'Void', creating: 'Creating checkout', pending: 'Awaiting payment', unknown: 'Status unconfirmed', failed: 'Failed', expired: 'Expired', needs_review: 'Needs review'};
        return badge(labels[status] || human(status) || 'Not set', ['active', 'paid'].includes(status) ? 'green' : ['past_due', 'failed', 'needs_review'].includes(status) ? 'red' : ['trialing', 'open', 'pending', 'unknown', 'creating'].includes(status) ? 'amber' : '');
    }
    function companyAccess(company) {
        if (company.operations_paused) return badge('Operations paused', 'red');
        if (company.subscription?.can_write === false) return badge('Access limited by subscription', 'amber');
        return badge('Operations available', 'green');
    }
    function paymentLabel(invoice) {
        if (invoice.payment_source === 'paymongo_test' || invoice.payment_provider === 'paymongo') return 'PayMongo · test';
        if (invoice.payment_source === 'simulation' || invoice.payment_method === 'simulation') return 'Local simulation';
        return invoice.status === 'paid' ? 'Recorded payment' : 'Not paid';
    }
    const planName = id => state.plans.find(plan => plan.id === id)?.name || human(id) || 'No plan';
    function field(name, label, value = '', options = {}) {
        const {type = 'text', max = 300, required = false, hint = '', full = false, min = null, textarea = false} = options;
        const attrs = `name="${escape(name)}" id="field-${escape(name)}"${required ? ' required' : ''}${max ? ` maxlength="${max}"` : ''}${min != null ? ` minlength="${min}"` : ''}`;
        return `<label class="field${full ? ' full' : ''}"><span>${escape(label)}</span>${textarea ? `<textarea ${attrs} rows="3">${escape(value)}</textarea>` : `<input ${attrs} type="${escape(type)}" value="${escape(value)}">`}${hint ? `<small>${escape(hint)}</small>` : ''}</label>`;
    }
    function select(name, label, value, options, required = false) {
        return `<label class="field"><span>${escape(label)}</span><select name="${escape(name)}" id="field-${escape(name)}"${required ? ' required' : ''}>${options.map(option => `<option value="${escape(option[0])}"${String(value) === String(option[0]) ? ' selected' : ''}>${escape(option[1])}</option>`).join('')}</select></label>`;
    }
    function heading(title, subtitle, actions = '') {
        return `<div class="page-heading"><div><p class="eyebrow">THE PLATFORM / IN VIEW</p><h1>${escape(title)}</h1><p>${escape(subtitle)}</p></div>${actions ? `<div class="heading-actions">${actions}</div>` : ''}</div>`;
    }
    function quiet(title, description, icon = 'document', action = '') {
        return `<div class="quiet-state">${svg(icon)}<h3>${escape(title)}</h3><p>${escape(description)}</p>${action}</div>`;
    }
    function notice(title, message, type = 'neutral', action = '') {
        return `<div class="notice ${type}">${svg('info')}<div><strong>${escape(title)}</strong><p>${escape(message)}</p>${action}</div></div>`;
    }
    function toast(message, error = false) {
        const item = document.createElement('div');
        item.className = `toast${error ? ' error' : ''}`;
        item.textContent = message;
        document.querySelector('#developer-toasts').append(item);
        setTimeout(() => item.remove(), error ? 7000 : 4500);
    }
    async function api(path, options = {}) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(path, {method: options.method || 'GET', credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, ...(options.body !== undefined ? {'Content-Type': 'application/json'} : {})}, ...(options.body !== undefined ? {body: JSON.stringify(options.body)} : {})});
            const payload = await response.json().catch(() => null);
            if (!response.ok) {
                const messages = payload?.errors ? Object.values(payload.errors).flat().filter(value => typeof value === 'string') : [];
                const error = new Error(messages.length ? messages.join(' ') : payload?.message || (response.status === 401 ? 'Your developer session ended. Sign in again.' : 'The request could not be completed. Try refreshing.'));
                error.status = response.status;
                throw error;
            }
            if (!payload || typeof payload !== 'object') throw new Error('The platform returned an unreadable response. Refresh to check the latest state.');
            return payload;
        } catch (error) {
            if (!error.status) {
                error.status = 0;
                error.message = error.name === 'AbortError' ? 'The request took too long. Check the latest state before trying again.' : error.message || 'The connection was interrupted. Refresh to check the latest state.';
            }
            throw error;
        } finally { clearTimeout(timeout); }
    }
    function setIdentity(data) {
        if (data.developer) {
            state.identity = data.developer;
            document.querySelector('#developer-name').textContent = data.developer.name || data.developer.email || 'Developer account';
            const avatar = document.querySelector('#developer-avatar');
            avatar.textContent = (data.developer.name || data.developer.email || 'D').slice(0, 1).toUpperCase();
            avatar.title = data.developer.email || 'Developer account';
        }
        if (Array.isArray(data.plans)) state.plans = data.plans;
        if (data.payment_config) state.paymentConfig = data.payment_config;
        const connection = document.querySelector('#developer-connection');
        connection.classList.remove('offline');
        connection.querySelector('span').textContent = 'Connected · platform records';
    }
    function queryFor(section, filters) {
        const query = new URLSearchParams({section, page: String(filters.page || 1), per_page: '25'});
        if (filters.search) query.set('search', filters.search);
        if (filters.status && filters.status !== 'all') query.set('status', filters.status);
        if (filters.tenant_id) query.set('tenant_id', filters.tenant_id);
        return query;
    }
    async function loadList(section = state.section, force = false) {
        const list = state.lists[section];
        if (!force && list.data) { if (state.section === section) render(); return; }
        const token = ++list.request;
        list.loading = true; list.error = '';
        if (state.section === section) render();
        try {
            const data = await api(`/api/developer/bootstrap?${queryFor(section, list.filters)}`);
            if (token !== list.request) return;
            list.data = data; setIdentity(data);
        } catch (error) {
            if (token !== list.request) return;
            list.error = error.message;
            document.querySelector('#developer-connection').classList.add('offline');
            document.querySelector('#developer-connection span').textContent = 'Connection needs attention';
        } finally {
            if (token === list.request) {
                list.loading = false;
                if (state.section === section) render();
            }
        }
    }
    async function loadCompany(id, page = 1) {
        const token = ++state.companyRequest;
        state.companyId = Number(id); state.companyPage = page; state.companyLoading = true; state.companyError = '';
        render();
        try {
            const data = await api(`/api/developer/companies/${encodeURIComponent(id)}?page=${page}&per_page=25`);
            if (token !== state.companyRequest) return;
            state.companyData = data;
        } catch (error) { if (token === state.companyRequest) state.companyError = error.message; }
        finally { if (token === state.companyRequest) { state.companyLoading = false; if (state.section === 'companies') render(); } }
    }
    function closeSidebar() {
        document.querySelector('#developer-sidebar').classList.remove('open');
        document.querySelector('#developer-shade').classList.remove('open');
        document.querySelector('#developer-menu').setAttribute('aria-expanded', 'false');
    }
    function navigate(section, companyId = null, push = true) {
        if (!sections[section]) section = 'overview';
        state.section = section;
        if (section === 'companies' && companyId) {
            if (state.companyId !== Number(companyId)) state.companyData = null;
            state.companyId = Number(companyId); state.companyTab = 'overview';
        } else { state.companyId = null; state.companyError = ''; ++state.companyRequest; }
        if (push) history.pushState({}, '', `/developer/${section}${companyId ? `?company=${encodeURIComponent(companyId)}` : ''}`);
        closeSidebar();
        document.querySelector('#developer-main').focus({preventScroll: true});
        render();
        if (section === 'companies' && companyId) { loadList(section); loadCompany(companyId); }
        else if (section === 'content') { loadList(section); if (!state.content.data) loadContent(); }
        else loadList(section);
    }
    function listState(section) {
        const list = state.lists[section];
        let output = '';
        if (list.error) output += notice('The latest records could not be loaded.', `${list.error} The previous successful results remain visible.`, 'error', button('Try again', 'refresh', '', '', 'refresh'));
        if (list.loading && list.data) output += notice('Refreshing platform records', 'The last loaded records stay visible while the latest data loads.');
        return output;
    }
    function pagination(meta, type = 'list') {
        if (!meta || Number(meta.last_page) < 2) return '';
        const page = Number(meta.current_page || 1), last = Number(meta.last_page || 1);
        return `<div class="pagination"><span>${plural(meta.total, 'record')} · Page ${page} of ${last}</span><div><button class="button" data-action="${type}-page" data-id="${page - 1}"${page <= 1 ? ' disabled' : ''}>← Previous</button><button class="button" data-action="${type}-page" data-id="${page + 1}"${page >= last ? ' disabled' : ''}>Next →</button></div></div>`;
    }
    function filters(section) {
        const list = state.lists[section], drafts = list.drafts;
        return `<section class="panel filter-panel"><form id="developer-filter-form" class="filter-form"><div class="filter-grid">${field('search', section === 'billing' ? 'Search invoices or companies' : section === 'activity' ? 'Search platform activity' : 'Search companies', drafts.search, {max: 100, hint: section === 'activity' ? 'Find a company, action, or recorded reason.' : 'Company names are matched on the server.'})}${section === 'billing' ? select('status', 'Invoice status', drafts.status, [['all', 'All statuses'], ['open', 'Open'], ['paid', 'Paid'], ['void', 'Void']]) : ''}</div><div class="filter-footer"><p>${section === 'activity' ? 'Platform actions show the owner, company, and recorded reason.' : 'Read-only lists. Open a company to review its subscription and controls.'}</p><div><button class="button subtle" type="button" data-action="reset-filters">Reset filters</button><button class="button" type="submit"${list.loading ? ' disabled' : ''}>Apply filters</button></div></div></form></section>`;
    }
    function companyTable(companies = [], compact = false) {
        if (!companies.length) return quiet('No companies found.', 'Try a different search. Company workspaces appear here after registration.', 'company');
        return `<div class="table-wrap"><table><thead><tr><th>Company</th><th>Access</th><th>Subscription</th>${compact ? '' : '<th>Usage</th>'}<th><span class="sr-only">Actions</span></th></tr></thead><tbody>${companies.map(company => `<tr><td class="table-company"><strong class="table-primary">${escape(company.name)}</strong><span class="table-secondary">Company #${escape(company.id)}${company.is_demo ? ' · Sample workspace' : ''}</span></td><td>${companyAccess(company)}</td><td><strong class="table-primary">${escape(planName(company.subscription?.plan))}</strong><span class="table-secondary">${company.subscription ? `${statusBadge(company.subscription.status)} · ${escape(human(company.subscription.billing_cycle))}` : 'No subscription record'}</span></td>${compact ? '' : `<td><span>${plural(company.usage?.locations, 'location')}</span><span class="table-secondary">${plural(company.usage?.team_members, 'active member')}</span></td>`}<td class="table-action">${button('View company', 'company', company.id)}</td></tr>`).join('')}</tbody></table></div>`;
    }
    function providerPanel() {
        const config = state.paymentConfig;
        if (!config) return quiet('Payment configuration unavailable.', 'Refresh platform records to load the current configuration.', 'card');
        const paymongo = config.provider === 'paymongo';
        return `<div class="provider-detail">${badge(paymongo ? 'PayMongo · test mode' : 'Local simulation', 'amber')}<h3>${escape(paymongo ? 'One-time test checkout' : 'Local billing records')}</h3><p>${escape(config.notice || (paymongo ? 'Invoice payments use PayMongo test mode. No live payment collection or automatic debit is enabled.' : 'Simulated payments are local records. No payment provider processes them.'))}</p><div class="provider-metadata">${badge(config.configured ? 'Configured' : 'Setup incomplete', config.configured ? 'green' : 'amber')}${paymongo ? badge(config.webhook_configured ? 'Webhook configured' : 'Webhook not configured', config.webhook_configured ? 'green' : 'amber') : ''}</div>${config.reason ? `<p>${escape(config.reason)}</p>` : ''}</div>`;
    }
    function renderOverview(data) {
        const summary = data.overview || {};
        const subscriptionCount = typeof summary.subscriptions === 'number' ? summary.subscriptions : null;
        return `${heading('Every business. One clear view.', 'Review company access, subscriptions, and the content on your public website.', button('Refresh overview', 'refresh', '', '', 'refresh'))}${listState('overview')}${summary.notice ? notice('Local platform records', summary.notice) : ''}<div class="stats-grid">${stat('Companies', summary.companies, `${plural(summary.demo_companies, 'sample workspace')} included`, 'company')}${stat('Paused companies', summary.paused_companies, 'Operational access is paused until resumed.', 'pause')}${stat('Subscriptions', subscriptionCount, 'Recorded company subscription records.', 'document')}${stat('Open invoices', summary.open_invoices, 'Awaiting payment confirmation.', 'card')}</div><div class="quick-links"><a class="quick-link" href="/developer/companies" data-nav="companies">${svg('company')}<div><strong>Manage company access</strong><small>Review a business before making a change.</small></div><span>↗</span></a><a class="quick-link" href="/developer/billing" data-nav="billing">${svg('card')}<div><strong>Review billing records</strong><small>Keep test payments and simulations clear.</small></div><span>↗</span></a><a class="quick-link" href="/developer/content" data-nav="content">${svg('globe')}<div><strong>Edit website content</strong><small>Save a draft, preview it, then publish.</small></div><span>↗</span></a></div><div class="dashboard-grid"><section class="panel"><div class="panel-header"><div><h2>Company workspaces</h2><p>Open a company to review access and subscription details.</p></div>${button('View all', 'nav-companies')}</div>${companyTable(data.companies?.data || [], true)}</section><section class="panel"><div class="panel-header"><div><h2>Payment records, clearly labeled</h2><p>These totals do not represent live payment collection.</p></div></div><div class="list-row"><div><strong>Local simulated payments</strong><small>Paid invoice records created through simulation.</small></div><strong>${money(summary.paid_simulation_total)}</strong></div><div class="list-row"><div><strong>PayMongo test confirmations</strong><small>Provider-confirmed payments in test mode.</small></div><strong>${money(summary.paid_paymongo_test_total)}</strong></div>${providerPanel()}</section></div>`;
    }
    function stat(label, value, note, icon) { return `<div class="stat-card"><div class="stat-label">${escape(label)}${svg(icon)}</div><div class="stat-value">${count(value)}</div><p class="stat-note">${escape(note)}</p></div>`; }
    function renderCompanies(data) {
        const companies = data.companies || {data: [], meta: {}};
        return `${heading('Good businesses need room to grow.', 'Review each company’s access, usage, and subscription without entering its staff workspace.', button('Refresh companies', 'refresh', '', '', 'refresh'))}${filters('companies')}${listState('companies')}<section class="panel"><div class="panel-header"><div><h2>Company workspaces</h2><p>${plural(companies.meta?.total ?? companies.data.length, 'company', 'companies')} matching the current search.</p></div></div>${companyTable(companies.data)}${pagination(companies.meta)}</section>`;
    }
    function invoiceTable(invoices, companyContext = false) {
        if (!invoices.length) return quiet('No invoices to show.', 'Invoices appear when a company creates or renews a billing record.', 'document');
        return `<div class="table-wrap"><table><thead><tr><th>Invoice</th>${companyContext ? '' : '<th>Company</th>'}<th>Plan / cycle</th><th>Amount</th><th>Status / payment source</th><th>Due</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>${invoices.map(invoice => `<tr><td><strong class="table-primary">${escape(invoice.number)}</strong><span class="table-secondary">#${escape(invoice.id)}</span></td>${companyContext ? '' : `<td class="table-company"><strong class="table-primary">${escape(invoice.company_name)}</strong><span class="table-secondary">Company #${escape(invoice.tenant_id)}</span></td>`}<td>${escape(planName(invoice.plan))}<span class="table-secondary">${escape(human(invoice.billing_cycle))}</span></td><td><strong>${money(invoice.amount)}</strong></td><td><div class="status-cell">${statusBadge(invoice.status)}${badge(paymentLabel(invoice), invoice.payment_provider === 'paymongo' || invoice.payment_source === 'paymongo_test' || invoice.payment_method === 'simulation' ? 'amber' : '')}</div></td><td>${date(invoice.due_at)}</td><td class="table-action">${button('View invoice', 'invoice-view', invoice.id)}</td></tr>`).join('')}</tbody></table></div>`;
    }
    function renderBilling(data) {
        const invoices = data.invoices || {data: [], meta: {}};
        return `${heading('Billing, with a clear paper trail.', 'Review subscriptions and immutable invoice quotes. Company administrators handle test checkout in their own workspace.', button('Refresh billing', 'refresh', '', '', 'refresh'))}${notice('Payment modes stay separate', 'PayMongo records use test mode. Historical simulated invoices remain local simulations. Renewals create invoices; they do not charge a saved payment method.')}${filters('billing')}${listState('billing')}<section class="panel"><div class="panel-header"><div><h2>Company invoices</h2><p>${plural(invoices.meta?.total ?? invoices.data.length, 'invoice')} matching these filters. Open a company to review its subscription.</p></div></div>${invoiceTable(invoices.data)}${pagination(invoices.meta)}</section><div class="section-title"><h2>Plan catalog</h2><p>Prices and limits come from the application’s plan configuration. This catalog is read-only.</p></div><div class="plan-grid">${state.plans.map(plan => `<section class="panel plan-card"><h2>${escape(plan.name)}</h2><div class="plan-price">${money(plan.monthly_price)}<small>per month</small></div><div class="plan-limits"><p>${plural(plan.limits?.locations, 'location')}</p><p>${plan.limits?.team_members == null ? 'Unlimited active team members' : plural(plan.limits.team_members, 'active team member')}</p></div><p class="plan-annual">${money(plan.annual_price)} billed annually.</p></section>`).join('')}</div>`;
    }
    function subscriptionDetails(company) {
        const sub = company.subscription;
        if (!sub) return notice('No subscription record yet', 'This company has no recorded subscription. Opening this page does not create one.');
        return `<section class="panel"><div class="panel-header"><div><h2>Subscription details</h2><p>Status reflects the current subscription dates. Viewing these records does not collect a payment.</p></div>${statusBadge(sub.status)}</div><div class="panel-body"><div class="invoice-lines"><div><span>Current plan</span><strong>${escape(planName(sub.plan))} · ${escape(human(sub.billing_cycle))}</strong></div><div><span>Trial ends</span><strong>${date(sub.trial_ends_at, true)}</strong></div><div><span>Paid period</span><strong>${sub.current_period_start ? `${date(sub.current_period_start)} – ${date(sub.current_period_end)}` : 'No paid period recorded'}</strong></div><div><span>Access through</span><strong>${sub.demo_exempt || company.is_demo ? 'Sample workspace available for practice' : date(sub.access_ends_at, true)}</strong></div><div><span>Renewal</span><strong>${sub.cancel_at_period_end ? 'Canceled at period end' : 'Renewal invoices enabled'}</strong></div><div><span>Scheduled plan</span><strong>${sub.pending_plan ? `${escape(planName(sub.pending_plan))} · ${escape(human(sub.pending_billing_cycle))}` : 'No scheduled change'}</strong></div></div></div></section>`;
    }
    function renderCompany() {
        const data = state.companyData;
        const company = data?.company;
        const head = heading(company?.name || 'Company details', 'Review company access and billing records. Staff, products, and sales remain in the company workspace.', `${button('All companies', 'companies-back', '', 'subtle', 'back')}${button('Refresh company', 'company-refresh', state.companyId, '', 'refresh')}`);
        const errors = state.companyError ? notice('Company details could not be refreshed.', state.companyError, 'error', button('Try again', 'company-refresh', state.companyId)) : '';
        if (!company) return `${head}${errors}${state.companyLoading ? quiet('Loading company details…', 'Reading the company’s recorded state.', 'company') : ''}`;
        const sub = company.subscription, usage = company.usage || {};
        const overview = `<div class="usage-grid">${usageCard('Locations', usage.locations, usage.limits?.locations)}${usageCard('Active team members', usage.team_members, usage.limits?.team_members)}<div class="usage-card"><span>Company created</span><strong style="font-size:17px">${date(company.created_at)}</strong><small>Company #${escape(company.id)}</small></div></div>${subscriptionDetails(company)}`;
        const invoices = data.invoices || {data: [], meta: {}};
        const invoiceOutput = `<section class="panel"><div class="panel-header"><div><h2>Company invoices</h2><p>Amounts are original invoice quotes. Payment source stays attached to the recorded payment.</p></div></div>${invoiceTable(invoices.data, true)}${pagination(invoices.meta, 'company')}<p class="table-note">Read-only invoices. This portal does not mark invoices paid or launch a provider checkout.</p></section>`;
        return `${head}${errors}${state.companyLoading ? notice('Refreshing company details', 'The last loaded state stays visible while the latest records load.') : ''}<section class="panel company-summary"><div><div class="company-summary-meta">${companyAccess(company)}${company.is_demo ? badge('Sample workspace', 'amber') : ''}${sub ? statusBadge(sub.status) : ''}</div><h2>${escape(company.name)}</h2><p>Pause operational access without deleting company data. Subscription changes are recorded separately from access changes.</p></div><div class="company-summary-details"><div class="detail-pair"><span>Plan</span><strong>${escape(planName(sub?.plan))}</strong></div><div class="detail-pair"><span>Billing cycle</span><strong>${escape(human(sub?.billing_cycle) || 'Not set')}</strong></div><div class="detail-pair"><span>Renewal</span><strong>${sub?.cancel_at_period_end ? 'Canceled at period end' : sub ? 'Invoices enabled' : 'Not set'}</strong></div></div></section><div class="company-actions">${button(company.operations_paused ? 'Resume operations' : 'Pause operations', 'company-action', company.operations_paused ? 'resume' : 'pause', company.operations_paused ? '' : 'subtle')}${button('Change plan', 'company-action', 'plan')}${button(sub?.cancel_at_period_end ? 'Resume renewal' : 'Cancel renewal', 'company-action', sub?.cancel_at_period_end ? 'resume-renewal' : 'cancel-renewal')}${button('Create invoice', 'company-action', 'invoice')}</div><div class="detail-tabs" role="tablist" aria-label="Company details"><button role="tab" aria-selected="${state.companyTab === 'overview'}" class="${state.companyTab === 'overview' ? 'active' : ''}" data-action="company-tab" data-id="overview">Company overview</button><button role="tab" aria-selected="${state.companyTab === 'invoices'}" class="${state.companyTab === 'invoices' ? 'active' : ''}" data-action="company-tab" data-id="invoices">Invoices</button><button role="tab" aria-selected="${state.companyTab === 'attempts'}" class="${state.companyTab === 'attempts' ? 'active' : ''}" data-action="company-tab" data-id="attempts">Payment attempts</button></div>${state.companyTab === 'invoices' ? invoiceOutput : state.companyTab === 'attempts' ? renderAttempts(data.attempts || [], data.attempt_total) : overview}`;
    }
    function usageCard(label, value, limit) {
        return `<div class="usage-card"><span>${escape(label)}</span><strong>${count(value)}${limit == null ? '' : ` / ${count(limit)}`}</strong><small>${limit == null ? 'No configured limit' : 'Current plan capacity'}</small></div>`;
    }
    function attemptRows(attempts) {
        return attempts.map(attempt => `<div class="attempt-row"><div><strong>${escape(attempt.reference || `Attempt #${attempt.id}`)}</strong>${statusBadge(attempt.status)}</div><small>${money(attempt.amount)} · ${escape(attempt.mode === 'test' ? 'PayMongo test' : human(attempt.mode))} · ${date(attempt.created_at, true)}</small>${attempt.confirmed_at ? `<small>Confirmed ${date(attempt.confirmed_at, true)}</small>` : ''}${attempt.session_id ? `<small>Checkout session: ${escape(attempt.session_id)}</small>` : ''}${attempt.failure_summary ? `<small>${escape(attempt.failure_summary)}</small>` : ''}</div>`).join('');
    }
    function renderAttempts(attempts, total) {
        return `<section class="panel"><div class="panel-header"><div><h2>PayMongo test attempts</h2><p>Read-only provider evidence. A browser return does not confirm payment.</p></div></div><div class="panel-body">${attempts.length ? attemptRows(attempts) : quiet('No provider attempts recorded.', 'Local simulated invoices do not create PayMongo checkout attempts.', 'card')}</div>${Number(total) > attempts.length ? `<p class="table-note">Showing the latest ${count(attempts.length)} of ${plural(total, 'attempt')}.</p>` : ''}</section>`;
    }
    const actionLabels = {'developer.account_invited': 'Developer setup link created', 'developer.account_activated': 'Developer access activated', 'company.paused': 'Company operations paused', 'company.resumed': 'Company operations resumed', 'subscription.plan_changed': 'Subscription plan changed', 'subscription.renewal_canceled': 'Renewal canceled', 'subscription.renewal_resumed': 'Renewal resumed', 'subscription.invoice_created': 'Invoice created', 'content.draft_saved': 'Website draft saved', 'content.published': 'Website content published'};
    function renderActivity(data) {
        const activity = data.activity || {data: [], meta: {}};
        return `${heading('A reason behind every change.', 'Review successful developer actions, who made them, and the company or website content affected.', button('Refresh activity', 'refresh', '', '', 'refresh'))}${filters('activity')}${listState('activity')}<section class="panel"><div class="panel-header"><div><h2>Platform activity</h2><p>${plural(activity.meta?.total ?? activity.data.length, 'record')} matching this search. Times are shown in Manila.</p></div></div>${activity.data.length ? `<div class="table-wrap"><table><thead><tr><th>Action / actor</th><th>Company</th><th>Reason</th><th>Recorded details</th><th>Manila time</th></tr></thead><tbody>${activity.data.map(row => `<tr><td class="activity-detail"><strong class="table-primary">${escape(actionLabels[row.action] || human(row.action).replace(/\./g, ' / '))}</strong><span class="table-secondary">${escape(row.actor?.name || (row.action === 'developer.account_invited' ? 'System operator' : 'Developer account'))}</span></td><td class="table-company">${escape(row.company_name || (row.tenant_id ? `Company #${row.tenant_id}` : ['developer.account_invited', 'developer.account_activated'].includes(row.action) ? 'Platform account' : 'Public website'))}</td><td class="activity-reason">${escape(row.reason || '—')}</td><td class="activity-detail">${metadataText(row.metadata)}</td><td>${date(row.created_at, true)}</td></tr>`).join('')}</tbody></table></div>` : quiet('No platform actions to show.', 'Reviewed company and website changes appear here after they succeed.', 'activity')}${pagination(activity.meta)}</section>`;
    }
    function metadataText(metadata) {
        if (!metadata || typeof metadata !== 'object') return '—';
        const allowed = ['plan', 'billing_cycle', 'status', 'invoice_id', 'invoice_number', 'version', 'operations_paused', 'cancel_at_period_end', 'pending_plan', 'pending_billing_cycle'];
        const entries = Object.entries(metadata).filter(([key, value]) => allowed.includes(key) && ['string', 'number', 'boolean'].includes(typeof value));
        const transitions = metadata.before && metadata.after ? allowed.filter(key => metadata.before[key] !== metadata.after[key] && [metadata.before[key], metadata.after[key]].every(value => value == null || ['string', 'number', 'boolean'].includes(typeof value))).map(key => `<span class="table-secondary">${escape(human(key))}: ${escape(displayPrimitive(metadata.before[key]))} → ${escape(displayPrimitive(metadata.after[key]))}</span>`) : [];
        return [...entries.map(([key, value]) => `<span class="table-secondary">${escape(human(key))}: ${escape(displayPrimitive(value))}</span>`), ...transitions].join('') || '—';
    }
    function displayPrimitive(value) { return typeof value === 'boolean' ? (value ? 'Yes' : 'No') : value == null ? 'Not set' : String(value); }
    function paint(html) {
        const active = document.activeElement;
        const focus = view.contains(active) && active.id ? {id: active.id, start: active.selectionStart, end: active.selectionEnd} : null;
        view.innerHTML = html;
        if (focus) {
            const replacement = document.getElementById(focus.id);
            if (replacement && view.contains(replacement)) {
                replacement.focus({preventScroll: true});
                if (typeof focus.start === 'number' && replacement.setSelectionRange) {
                    try { replacement.setSelectionRange(focus.start, focus.end); } catch (_) { /* Select and date controls do not expose a text selection. */ }
                }
            }
        }
    }
    function render() {
        document.querySelector('#developer-breadcrumb').textContent = sections[state.section];
        document.querySelectorAll('#developer-nav a[data-view]').forEach(anchor => {
            const active = anchor.dataset.view === state.section;
            anchor.classList.toggle('active', active);
            if (active) anchor.setAttribute('aria-current', 'page'); else anchor.removeAttribute('aria-current');
        });
        if (state.section === 'content') { renderContent(); return; }
        if (state.section === 'companies' && state.companyId) { paint(renderCompany()); return; }
        const list = state.lists[state.section];
        if (!list.data) {
            paint(`${heading(sections[state.section], 'Loading the platform’s recorded state.', '')}${list.error ? notice('Platform records could not be loaded.', list.error, 'error', button('Try again', 'refresh', '', '', 'refresh')) : quiet('Loading platform records…', 'Your company workspace stays available separately.', 'company')}`);
            return;
        }
        const renderers = {overview: renderOverview, companies: renderCompanies, billing: renderBilling, activity: renderActivity};
        paint(renderers[state.section](list.data));
    }

    const contentFields = [
        ['site_title', 'Website title', 120, 'Used in the browser tab and search metadata.'],
        ['meta_description', 'Search description', 300, 'A short description of the public website.'],
        ['hero_kicker', 'Hero eyebrow', 80, 'The small line above the main headline.'],
        ['hero_title_line1', 'Headline · first line', 60, ''],
        ['hero_title_line2', 'Headline · second line', 60, ''],
        ['hero_description', 'Hero description', 400, 'Keep the main benefit clear and easy to read.'],
        ['primary_cta', 'Primary button label', 60, 'The button keeps its existing destination.'],
        ['footer_tagline', 'Footer tagline', 120, ''],
        ['support_email', 'Support email', 255, 'Optional. Leave blank to hide the email link.']
    ];
    function contentValue(source) {
        return Object.fromEntries(contentFields.map(([key]) => [key, typeof source?.[key] === 'string' ? source[key] : '']));
    }
    function draftDiffers() {
        const saved = contentValue(state.content.data?.draft);
        return contentFields.some(([key]) => saved[key] !== state.content.draft?.[key]);
    }
    function unpublishedChanges() {
        const data = state.content.data;
        if (!data) return false;
        return contentFields.some(([key]) => (data.draft?.[key] || '') !== (data.published?.[key] || ''));
    }
    async function loadContent(discard = false) {
        const current = state.content, token = ++current.request;
        current.loading = true; current.error = '';
        if (state.section === 'content') renderContent();
        try {
            const data = await api('/api/developer/content');
            if (token !== current.request) return;
            current.data = data;
            if (!current.dirty || discard) { current.draft = contentValue(data.draft); current.dirty = false; }
        } catch (error) { if (token === current.request) current.error = error.message; }
        finally { if (token === current.request) { current.loading = false; if (state.section === 'content') renderContent(); } }
    }
    function heroPreview(draft) {
        return `<small>HEADLINE PREVIEW</small><p class="preview-kicker">${escape(draft.hero_kicker)}</p><h2>${escape(draft.hero_title_line1)}<br><span>${escape(draft.hero_title_line2)}</span></h2><p>${escape(draft.hero_description)}</p><span class="preview-cta">${escape(draft.primary_cta)} ↗</span><p class="preview-footer">${escape(draft.footer_tagline)}</p>`;
    }
    function updateContentFeedback() {
        const dirty = state.content.dirty;
        const status = document.querySelector('#content-dirty-status');
        if (status) { status.textContent = dirty ? 'Unsaved changes · save a draft first' : 'All edits saved in this draft'; status.classList.toggle('content-dirty', dirty); }
        const publish = document.querySelector('[data-action="content-publish"]');
        if (publish) publish.disabled = dirty || state.content.loading || state.actionBusy;
        const preview = document.querySelector('#content-hero-preview');
        if (preview) preview.innerHTML = heroPreview(state.content.draft || {});
    }
    function renderContent() {
        const current = state.content;
        const actions = `${button('Refresh draft', 'content-refresh', '', '', 'refresh')}<a class="button subtle" href="/developer/content/preview" target="_blank" rel="noopener">Preview saved draft ↗</a>`;
        const header = heading('Your website. Your words.', 'Save a draft, preview the local site, then publish when the wording is ready.', actions);
        if (!current.data || !current.draft) {
            paint(`${header}${current.error ? notice('Website content could not be loaded.', current.error, 'error', button('Try again', 'content-refresh')) : quiet('Loading website content…', 'Reading the saved draft and published copy.', 'globe')}`);
            return;
        }
        const draft = current.draft;
        const makeField = key => {
            const [name, label, max, hint] = contentFields.find(row => row[0] === key);
            return field(name, label, draft[name], {max, hint, required: name !== 'support_email', type: name === 'support_email' ? 'email' : 'text', textarea: ['meta_description', 'hero_description'].includes(name), full: ['meta_description', 'hero_description'].includes(name)});
        };
        paint(`${header}${current.error ? notice('The latest draft could not be loaded.', current.error, 'error', button('Reload saved draft', 'content-refresh')) : ''}${current.loading ? notice('Refreshing the saved draft', 'Typed edits stay available while the current server version loads.') : ''}<div class="content-layout"><section class="panel"><form id="developer-content-form" class="content-form"><h2>Website identity</h2><p>These fields update the public landing page. Plan prices continue to come from the application’s billing configuration.</p><div class="form-grid">${makeField('site_title')}${makeField('support_email')}${makeField('meta_description')}</div><div class="content-divider"><h3>Make the first impression clear.</h3><p>Edit the hero headline, description, and main button label.</p></div><div class="form-grid">${makeField('hero_kicker')}${makeField('primary_cta')}${makeField('hero_title_line1')}${makeField('hero_title_line2')}${makeField('hero_description')}</div><div class="content-divider"><h3>One last thought.</h3><p>The footer tagline appears at the bottom of the public website.</p></div>${makeField('footer_tagline')}<div class="content-toolbar"><span id="content-dirty-status" class="${current.dirty ? 'content-dirty' : ''}">${current.dirty ? 'Unsaved changes · save a draft first' : 'All edits saved in this draft'}</span><div><button class="button primary" type="submit"${current.loading || state.actionBusy ? ' disabled' : ''}>Save draft</button></div></div></form></section><aside class="content-sidebar"><section class="panel content-status">${badge(unpublishedChanges() ? 'Draft differs from published site' : 'Draft matches published site', unpublishedChanges() ? 'amber' : 'green')}<h2>Draft version ${escape(current.data.version)}</h2><p>Updated ${date(current.data.updated_at, true)} Manila time.</p><p style="margin-top:10px">Publishing updates this locally running website. It does not deploy the application.</p><button class="button dark" type="button" data-action="content-publish"${current.dirty || current.loading || state.actionBusy ? ' disabled' : ''}>Publish to local site</button><small>Save any typed changes before publishing. A recorded reason is required for both actions.</small></section><section class="panel content-preview" id="content-hero-preview">${heroPreview(draft)}</section></aside></div>`);
    }
    function openModal(title, subtitle, body) {
        state.modalBusy = false;
        modalContent.innerHTML = `<div class="modal-header"><div><h2 id="developer-modal-title">${escape(title)}</h2><p>${escape(subtitle)}</p></div><button class="icon-button" type="button" data-action="close-modal" aria-label="Close dialog">${svg('close')}</button></div><div class="modal-body">${body}</div>`;
        if (!modal.open) modal.showModal();
        modalContent.querySelector('input:not([type=hidden]),select,textarea')?.focus();
    }
    function modalError(message) {
        const container = modalContent.querySelector('#developer-modal-error');
        if (container) { container.textContent = message; container.setAttribute('role', 'alert'); }
        else toast(message, true);
    }
    function setModalBusy(busy) {
        state.modalBusy = busy;
        modalContent.querySelectorAll('button,input,select,textarea').forEach(element => { element.disabled = busy; });
    }
    function invalidateLists() {
        Object.values(state.lists).forEach(list => { ++list.request; list.loading = false; list.data = null; });
    }
    function reviewSummary(company, description) {
        return `<div class="review-summary"><strong>${escape(company.name)}</strong><p>${escape(description)}</p><dl><div><dt>Company</dt><dd>#${escape(company.id)}</dd></div><div><dt>Current plan</dt><dd>${escape(planName(company.subscription?.plan))} · ${escape(human(company.subscription?.billing_cycle))}</dd></div><div><dt>Operations</dt><dd>${company.operations_paused ? 'Paused' : 'Available'}</dd></div></dl></div>`;
    }
    function companyAction(action) {
        const company = state.companyData?.company;
        if (!company || state.companyLoading || state.actionBusy) return;
        if (action === 'plan' && !state.plans.length) { toast('Refresh the company page to load the plan catalog before changing plans.', true); return; }
        const actions = {
            pause: ['Pause company operations', 'Pause operational access while retaining the company’s data and billing records.', 'Pause operations'],
            resume: ['Resume company operations', 'Restore operational access. Subscription restrictions continue to apply independently.', 'Resume operations'],
            plan: ['Review a plan change', 'For a paid subscription, the selected plan starts at the next renewal. Unpaid or trial selections change immediately without extending the trial.', 'Save plan change'],
            'cancel-renewal': ['Cancel renewal', 'Keep the current paid or trial period, then stop renewal invoices. This does not refund a recorded payment or delete the company.', 'Cancel renewal'],
            'resume-renewal': ['Resume renewal', 'Enable renewal invoices again. Renewals do not charge a stored card automatically.', 'Resume renewal'],
            invoice: ['Create a company invoice', 'Create or retrieve the company’s current open invoice. This records a quote; it does not mark it paid or charge a payment provider.', 'Create invoice']
        };
        const details = actions[action];
        if (!details) return;
        state.modalAction = {kind: 'company', action, id: company.id};
        const choices = action === 'plan' ? `<div class="form-grid">${select('plan', 'New plan', company.subscription?.pending_plan || company.subscription?.plan || state.plans[0]?.id, state.plans.map(plan => [plan.id, `${plan.name} · ${money(plan.monthly_price)}/month`]), true)}${select('billing_cycle', 'Billing cycle', company.subscription?.pending_billing_cycle || company.subscription?.billing_cycle || 'monthly', [['monthly', 'Monthly'], ['annual', 'Annual']], true)}</div><p class="modal-note" id="company-plan-quote"></p>` : '';
        openModal(details[0], 'Review the affected company and leave a reason for the platform activity log.', `<form id="developer-company-action-form"><div class="inline-error" id="developer-modal-error"></div>${reviewSummary(company, details[1])}${choices}${field('reason', 'Reason for this change', '', {textarea: true, required: true, min: 3, max: 300, hint: 'Use 3–300 characters. The reason is visible in the platform activity log.'})}<div class="modal-actions"><button class="button subtle" type="button" data-action="close-modal">Keep current state</button><button class="button primary" type="submit">${escape(details[2])}</button></div><p class="modal-note">The server checks current subscription and checkout state before accepting this action.</p></form>`);
        if (action === 'plan') updatePlanQuote();
    }
    function updatePlanQuote() {
        const form = document.querySelector('#developer-company-action-form');
        const note = document.querySelector('#company-plan-quote');
        if (!form || !note) return;
        const plan = state.plans.find(value => value.id === form.elements.plan?.value);
        if (!plan) return;
        note.textContent = `${money(form.elements.billing_cycle?.value === 'annual' ? plan.annual_price : plan.monthly_price)} per ${form.elements.billing_cycle?.value === 'annual' ? 'year' : 'month'} · ${plural(plan.limits?.locations, 'location')} · ${plan.limits?.team_members == null ? 'unlimited active members' : plural(plan.limits.team_members, 'active member')}. Current usage must fit the selected plan.`;
    }
    async function submitCompany(form) {
        if (state.actionBusy || !state.modalAction || state.modalAction.kind !== 'company') return;
        const operation = {...state.modalAction};
        const values = new FormData(form);
        const payload = {reason: String(values.get('reason') || '').trim()};
        if (operation.action === 'plan') { payload.plan = values.get('plan'); payload.billing_cycle = values.get('billing_cycle'); }
        state.actionBusy = true; setModalBusy(true);
        try {
            const result = await api(`/api/developer/companies/${operation.id}/${operation.action}`, {method: 'POST', body: payload});
            invalidateLists();
            modal.close(); state.modalAction = null;
            toast(result.message || 'Company changes saved.');
            if (state.companyId === operation.id) await loadCompany(operation.id, state.companyPage);
        } catch (error) {
            setModalBusy(false);
            if (error.status === 0 || error.status >= 500) {
                modalError(`${error.message} The result is unconfirmed. Refresh this company before making another change.`);
                const submit = form.querySelector('button[type=submit]');
                if (submit) submit.disabled = true;
                const actions = form.querySelector('.modal-actions');
                if (actions && !actions.querySelector('[data-action="action-reconcile"]')) actions.insertAdjacentHTML('beforeend', button('Check company state', 'action-reconcile', operation.id));
            } else modalError(error.message);
        } finally { state.actionBusy = false; state.modalBusy = false; }
    }
    function contentReview(kind) {
        const content = state.content;
        if (!content.data || content.loading || state.actionBusy) return;
        const form = document.querySelector('#developer-content-form');
        if (kind === 'save' && form && !form.reportValidity()) return;
        if (kind === 'publish' && content.dirty) { toast('Save your draft before publishing.', true); return; }
        state.modalAction = {kind: 'content', action: kind, version: content.data.version, content: contentValue(content.draft)};
        const draft = kind === 'save' ? content.draft : content.data.draft;
        openModal(kind === 'save' ? 'Save this website draft' : 'Publish to local site', kind === 'save' ? 'Save the draft without changing the published website.' : 'Publish the saved draft to the locally running public website.', `<form id="developer-content-action-form"><div class="inline-error" id="developer-modal-error"></div><div class="review-summary"><strong>${escape(draft?.site_title)}</strong><p>${escape(draft?.hero_title_line1)} ${escape(draft?.hero_title_line2)}</p><dl><div><dt>Draft version</dt><dd>${escape(content.data.version)}</dd></div><div><dt>Effect</dt><dd>${kind === 'save' ? 'Saved draft only' : 'Updates the local public website'}</dd></div></dl></div>${field('reason', kind === 'save' ? 'Reason for this draft' : 'Reason for publishing', '', {textarea: true, min: 3, max: 300, required: true, hint: 'Use 3–300 characters. The reason is recorded in platform activity.'})}<div class="modal-actions"><button class="button subtle" type="button" data-action="close-modal">Keep editing</button><button class="button primary" type="submit">${kind === 'save' ? 'Save draft' : 'Publish to local site'}</button></div></form>`);
    }
    async function submitContent(form) {
        if (state.actionBusy || state.modalAction?.kind !== 'content') return;
        const operation = {...state.modalAction};
        const reason = String(new FormData(form).get('reason') || '').trim();
        state.actionBusy = true; setModalBusy(true);
        const current = state.content, token = ++current.request;
        try {
            const data = await api(`/api/developer/content${operation.action === 'publish' ? '/publish' : ''}`, {method: operation.action === 'publish' ? 'POST' : 'PUT', body: {version: operation.version, reason, ...(operation.action === 'save' ? {content: operation.content} : {})}});
            if (token !== current.request) return;
            current.data = data; current.draft = contentValue(data.draft); current.dirty = false; current.error = ''; current.loading = false;
            invalidateLists();
            modal.close(); state.modalAction = null;
            toast(operation.action === 'save' ? 'Draft saved. Preview it before publishing.' : 'The local website now uses the published draft.');
            renderContent();
        } catch (error) {
            setModalBusy(false);
            if (error.status === 409) {
                modalError('Another change updated the saved version. Your typed draft is still here. Reload the saved draft before saving or publishing again.');
                form.querySelector('button[type=submit]').disabled = true;
                form.querySelector('.modal-actions').insertAdjacentHTML('beforeend', button('Review saved version', 'content-stale'));
            } else if (error.status === 0 || error.status >= 500) {
                modalError(`${error.message} The result is unconfirmed. Check the saved version before trying again.`);
                form.querySelector('button[type=submit]').disabled = true;
                form.querySelector('.modal-actions').insertAdjacentHTML('beforeend', button('Check saved version', 'content-stale'));
            } else modalError(error.message);
        } finally { state.actionBusy = false; state.modalBusy = false; }
    }
    function confirmContentReload() {
        if (!state.content.dirty) { loadContent(true); return; }
        state.modalAction = {kind: 'reload'};
        openModal('Reload the saved draft?', 'Your typed changes have not been saved.', `<div class="review-summary"><strong>Replace your current typed draft</strong><p>The saved server version will replace the fields you are editing. You can keep editing to preserve them.</p></div><div class="modal-actions"><button class="button subtle" type="button" data-action="close-modal">Keep editing</button><button class="button primary" type="button" data-action="content-discard-reload">Reload saved draft</button></div>`);
    }
    function findInvoice(id) {
        const companyInvoices = state.companyData?.invoices?.data || [];
        const allInvoices = Object.values(state.lists).flatMap(list => list.data?.invoices?.data || []);
        return [...companyInvoices, ...allInvoices].find(invoice => Number(invoice.id) === Number(id));
    }
    function viewInvoice(id) {
        const invoice = findInvoice(id);
        if (!invoice) { toast('Refresh the invoice list to view this record.', true); return; }
        const attempts = [...(state.companyData?.attempts || []).filter(attempt => Number(attempt.invoice_id) === Number(invoice.id)), invoice.latest_attempt, invoice.confirmed_attempt].filter(Boolean);
        const uniqueAttempts = [...new Map(attempts.map(attempt => [attempt.id, attempt])).values()];
        state.modalAction = null;
        openModal(invoice.number || 'Invoice details', 'Read-only billing record. This view cannot collect or confirm a payment.', `<div class="invoice-lines"><div><span>Company</span><strong>${escape(invoice.company_name || state.companyData?.company?.name || `Company #${invoice.tenant_id}`)}</strong></div><div><span>Plan / cycle</span><strong>${escape(planName(invoice.plan))} · ${escape(human(invoice.billing_cycle))}</strong></div><div><span>Status</span>${statusBadge(invoice.status)}</div><div><span>Payment source</span>${badge(paymentLabel(invoice), 'amber')}</div><div><span>Due</span><strong>${date(invoice.due_at, true)}</strong></div><div><span>Paid</span><strong>${date(invoice.paid_at, true)}</strong></div><div><span>Covered period</span><strong>${invoice.period_start ? `${date(invoice.period_start)} – ${date(invoice.period_end)}` : 'Starts after confirmed payment'}</strong></div><div class="invoice-total"><span>Invoice amount</span><strong>${money(invoice.amount)}</strong></div></div>${notice('Payment evidence', paymentLabel(invoice) === 'PayMongo · test' ? 'PayMongo confirmations here are test-mode records. They do not represent live payment collection.' : invoice.payment_method === 'simulation' ? 'This payment was locally simulated. No payment provider processed it.' : 'No confirmed provider payment is implied by this invoice status.')} ${uniqueAttempts.length ? `<div class="attempt-list"><h3>Recorded PayMongo test attempts</h3>${attemptRows(uniqueAttempts)}</div>` : ''}<div class="modal-actions"><button class="button" type="button" data-action="close-modal">Close invoice</button>${invoice.tenant_id ? button('View company', 'invoice-company', invoice.tenant_id) : ''}</div>`);
    }
    document.addEventListener('click', event => {
        const navigation = event.target.closest('#developer-nav a[data-view],a[data-nav]');
        if (navigation && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) { event.preventDefault(); navigate(navigation.dataset.view || navigation.dataset.nav); return; }
        const target = event.target.closest('[data-action]');
        if (!target || target.disabled) return;
        const action = target.dataset.action, id = target.dataset.id;
        if (action === 'close-modal') { if (!state.modalBusy) { modal.close(); state.modalAction = null; } return; }
        if (action === 'refresh') loadList(state.section, true);
        else if (action === 'nav-companies' || action === 'companies-back') navigate('companies');
        else if (action === 'company' || action === 'invoice-company') { if (modal.open) modal.close(); navigate('companies', id); }
        else if (action === 'company-refresh') { if (!state.companyLoading) { loadList('companies', true); loadCompany(id || state.companyId, state.companyPage); } }
        else if (action === 'company-tab') { state.companyTab = id; render(); }
        else if (action === 'company-action') companyAction(id);
        else if (action === 'action-reconcile') { modal.close(); state.modalAction = null; loadCompany(id, state.companyPage); }
        else if (action === 'list-page') { state.lists[state.section].filters.page = Number(id); loadList(state.section, true); }
        else if (action === 'company-page') { state.companyTab = 'invoices'; loadCompany(state.companyId, Number(id)); }
        else if (action === 'reset-filters') {
            const list = state.lists[state.section]; list.filters = {search: '', status: 'all', page: 1, tenant_id: ''}; list.drafts = {search: '', status: 'all'}; loadList(state.section, true);
        }
        else if (action === 'invoice-view') viewInvoice(id);
        else if (action === 'content-refresh') confirmContentReload();
        else if (action === 'content-publish') contentReview('publish');
        else if (action === 'content-discard-reload') { modal.close(); state.modalAction = null; loadContent(true); }
        else if (action === 'content-stale') { modal.close(); state.modalAction = null; confirmContentReload(); }
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (form.id === 'developer-filter-form') {
            event.preventDefault();
            const list = state.lists[state.section], fields = new FormData(form);
            list.drafts = {search: String(fields.get('search') || ''), status: String(fields.get('status') || 'all')};
            list.filters = {...list.filters, search: list.drafts.search.trim(), status: list.drafts.status, page: 1};
            loadList(state.section, true);
        } else if (form.id === 'developer-company-action-form') { event.preventDefault(); submitCompany(form); }
        else if (form.id === 'developer-content-form') { event.preventDefault(); contentReview('save'); }
        else if (form.id === 'developer-content-action-form') { event.preventDefault(); submitContent(form); }
    });
    document.addEventListener('input', event => {
        const form = event.target.closest('form');
        if (form?.id === 'developer-filter-form') state.lists[state.section].drafts[event.target.name] = event.target.value;
        if (form?.id === 'developer-content-form' && state.content.draft && contentFields.some(([key]) => key === event.target.name)) {
            state.content.draft[event.target.name] = event.target.value;
            state.content.dirty = draftDiffers();
            updateContentFeedback();
        }
    });
    document.addEventListener('change', event => {
        if (event.target.closest('#developer-filter-form')) state.lists[state.section].drafts[event.target.name] = event.target.value;
        if (event.target.closest('#developer-company-action-form')) updatePlanQuote();
    });
    modal.addEventListener('cancel', event => { if (state.modalBusy) event.preventDefault(); else state.modalAction = null; });
    modal.addEventListener('click', event => { if (event.target === modal && !state.modalBusy) { const rect = modal.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) { modal.close(); state.modalAction = null; } } });
    document.querySelector('#developer-menu').addEventListener('click', () => {
        const open = !document.querySelector('#developer-sidebar').classList.contains('open');
        document.querySelector('#developer-sidebar').classList.toggle('open', open);
        document.querySelector('#developer-shade').classList.toggle('open', open);
        document.querySelector('#developer-menu').setAttribute('aria-expanded', String(open));
    });
    document.querySelector('#developer-shade').addEventListener('click', closeSidebar);
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.open) closeSidebar(); });
    window.addEventListener('popstate', () => {
        const section = location.pathname.split('/')[2] || 'overview';
        const company = new URLSearchParams(location.search).get('company');
        navigate(section, section === 'companies' && /^\d+$/.test(company || '') ? company : null, false);
    });
    const initialCompany = new URLSearchParams(location.search).get('company');
    navigate(state.section, state.section === 'companies' && /^\d+$/.test(initialCompany || '') ? initialCompany : null, false);
})();
