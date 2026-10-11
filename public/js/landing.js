(() => {
    'use strict';
    const motionAllowed = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (motionAllowed && 'IntersectionObserver' in window) {
        document.documentElement.classList.add('js-motion');
        const observer = new IntersectionObserver(entries => entries.forEach(entry => {
            if (entry.isIntersecting) { entry.target.classList.add('visible'); observer.unobserve(entry.target); }
        }), { threshold: 0.08 });
        document.querySelectorAll('.reveal').forEach(element => observer.observe(element));
    }
    const menu = document.querySelector('.menu-toggle');
    const nav = document.querySelector('.desktop-nav');
    const setMenuOpen = (opened, restoreFocus = false) => {
        menu.setAttribute('aria-expanded', String(opened));
        menu.setAttribute('aria-label', opened ? 'Close navigation' : 'Open navigation');
        menu.textContent = opened ? '×' : '☰';
        nav.classList.toggle('mobile-open', opened);
        if (restoreFocus) menu.focus();
    };
    menu.addEventListener('click', () => setMenuOpen(menu.getAttribute('aria-expanded') !== 'true'));
    nav.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setMenuOpen(false)));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menu.getAttribute('aria-expanded') === 'true') {
            event.preventDefault();
            setMenuOpen(false, true);
        }
    });
    document.addEventListener('click', event => {
        if (!menu.contains(event.target) && !nav.contains(event.target)) setMenuOpen(false);
    });
    window.matchMedia('(max-width: 800px)').addEventListener('change', () => setMenuOpen(false));
    document.querySelectorAll('.industry-row').forEach(row => row.addEventListener('click', () => {
        const expanded = row.getAttribute('aria-expanded') !== 'true';
        document.querySelectorAll('.industry-row').forEach(other => {
            other.setAttribute('aria-expanded', 'false'); other.querySelector('b').textContent = '+';
        });
        row.setAttribute('aria-expanded', String(expanded)); row.querySelector('b').textContent = expanded ? '−' : '+';
    }));
    const peso = amount => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount);
    const pricingStatus = document.querySelector('#pricing-status');
    document.querySelectorAll('[data-billing]').forEach(button => button.addEventListener('click', () => {
        const annual = button.dataset.billing === 'annual';
        document.querySelectorAll('[data-billing]').forEach(other => {
            const selected = other === button; other.classList.toggle('active', selected); other.setAttribute('aria-pressed', String(selected));
        });
        document.querySelectorAll('[data-monthly]').forEach(price => {
            const amount = annual ? Number(price.dataset.annual) / 12 : Number(price.dataset.monthly);
            price.textContent = peso(amount).replace(/\.00$/, '');
            const note = price.closest('.plan').querySelector('.billing-note');
            note.textContent = annual ? note.dataset.annualNote : note.dataset.monthlyNote;
        });
        pricingStatus.textContent = annual
            ? 'Yearly pricing selected. Prices are shown per month, with the full yearly invoice below each price.'
            : 'Monthly pricing selected. Each price is for one monthly invoice.';
    }));
    let cart = [];
    const order = document.querySelector('#demo-order');
    const total = document.querySelector('#demo-total');
    const pay = document.querySelector('#demo-pay');
    const cartStatus = document.querySelector('#demo-cart-status');
    const emptyMarkup = order.innerHTML;
    const render = (announcement = '') => {
        order.innerHTML = cart.length ? cart.map(item => `<div class="demo-line"><div><strong>${item.name}</strong><small>${peso(item.price)} each</small></div><div><button data-remove="${item.id}" aria-label="Remove one ${item.name}">−</button><span>${item.quantity}</span><strong>${peso(item.price * item.quantity)}</strong></div></div>`).join('') : emptyMarkup;
        total.textContent = peso(cart.reduce((sum, item) => sum + item.price * item.quantity, 0));
        pay.disabled = cart.length === 0;
        const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);
        cartStatus.textContent = announcement
            ? `${announcement} ${itemCount} ${itemCount === 1 ? 'item' : 'items'} in sample order. Total ${total.textContent}.`
            : '';
    };
    document.querySelectorAll('.demo-product').forEach(product => product.addEventListener('click', () => {
        const found = cart.find(item => item.id === product.dataset.product);
        if (found) found.quantity += 1;
        else cart.push({id:product.dataset.product, name:product.dataset.name, price:Number(product.dataset.price), quantity:1});
        render(`Added ${product.dataset.name}.`);
    }));
    order.addEventListener('click', event => {
        const button = event.target.closest('[data-remove]'); if (!button) return;
        const item = cart.find(item => item.id === button.dataset.remove); if (!item) return;
        item.quantity -= 1; cart = cart.filter(item => item.quantity > 0); render(`Removed one ${item.name}.`);
    });
    document.querySelector('#demo-reset').addEventListener('click', () => {cart = []; render('Sample order cleared.');});
    let toastTimer;
    pay.addEventListener('click', () => {
        const amount = total.textContent; cart = []; render();
        const toast = document.querySelector('.demo-toast');
        const nextStep = document.body.dataset.companySignedIn === 'true'
            ? 'Return to your workspace to track your inventory.'
            : document.body.dataset.workspaceDemoEnabled === 'true'
                ? 'Open the live demo to try real stock tracking.'
                : 'Create your workspace to start tracking inventory.';
        toast.textContent = `Sample sale complete · ${amount}. ${nextStep}`;
        toast.classList.add('visible'); clearTimeout(toastTimer); toastTimer = setTimeout(() => toast.classList.remove('visible'), 5500);
    });
    const sitePreview = document.body.dataset.sitePreview === 'true';
    let variant = 'a';
    if (!sitePreview) {
        try { variant = sessionStorage.getItem('stockroom-variant') || (Math.random() > 0.5 ? 'b' : 'a'); sessionStorage.setItem('stockroom-variant', variant); } catch (_) {}
    }
    const track = event => {
        if (sitePreview) return;
        return fetch('/events', {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({event,variant}),keepalive:true}).catch(() => {});
    };
    track('landing_view');
    document.querySelectorAll('.trial-cta').forEach(link => {
        if (variant === 'b' && !link.closest('.plan') && !link.hasAttribute('data-cms-copy')) link.childNodes[0].textContent = 'Create your workspace';
        link.addEventListener('click', () => track('trial_click'));
    });
    document.querySelectorAll('form[action="/demo"]').forEach(form => form.addEventListener('submit', () => track('demo_open')));
    if ('IntersectionObserver' in window) {
        const pricingObserver = new IntersectionObserver(entries => { if(entries.some(entry => entry.isIntersecting)){track('pricing_view'); pricingObserver.disconnect();} }, {threshold:0.2});
        pricingObserver.observe(document.querySelector('#pricing'));
    }
})();
