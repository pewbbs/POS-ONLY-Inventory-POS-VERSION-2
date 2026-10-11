<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#191918">
    <meta name="robots" content="noindex, nofollow">
    <title>Workspace — POSONLY</title>
    <link rel="icon" href="/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('css/workspace.css') }}?v={{ filemtime(public_path('css/workspace.css')) }}">
    <script src="{{ asset('js/workspace.js') }}?v={{ filemtime(public_path('js/workspace.js')) }}" defer></script>
</head>
<body data-section="{{ request()->segment(2, 'overview') }}">
    <a class="skip-link" href="#main-content">Skip to workspace</a>
    <div class="sidebar-shade" id="sidebar-shade"></div>
    <aside class="sidebar" id="sidebar" aria-label="Workspace navigation">
        <a class="brand" href="/" aria-label="POSONLY home">
            <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
            <span>POSONLY<span class="brand-period">.</span></span>
        </a>
        <div class="business-switch"><span class="business-initial" id="business-initial">S</span><div><strong id="business-name">Your workspace</strong><span id="workspace-kind">Cloud inventory & POS</span></div><span class="business-caret">⌄</span></div>
        <p class="nav-caption">YOUR WORKSPACE</p>
        <nav id="main-nav">
            <a href="/app/overview" data-section="overview"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></svg><span>Overview</span></a>
            <a href="/app/pos" data-section="pos"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 3h16v11H4zM8 21h8M12 14v7M7 7h10M7 10h5"/></svg><span>Point of sale</span><span class="nav-dot"></span></a>
            <a href="/app/inventory" data-section="inventory"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 7 9-5 9 5v10l-9 5-9-5V7Zm0 0 9 5 9-5M12 12v10M7 4l9 5"/></svg><span>Inventory</span><span class="nav-badge" id="stock-badge" hidden></span></a>
            <a href="/app/purchases" data-section="purchases"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l3 12h11l3-9H6M10 7V3M14 7V3M9 20h.01M18 20h.01"/></svg><span>Purchasing</span></a>
            <a href="/app/channels" data-section="channels"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="3" width="7" height="6" rx="1"/><rect x="15" y="3" width="7" height="6" rx="1"/><rect x="8.5" y="15" width="7" height="6" rx="1"/><path d="M5.5 9v3H19V9M12 12v3"/></svg><span>Channels</span></a>
            <a href="/app/customers" data-section="customers"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M20 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/><path d="M16 3a4 4 0 0 1 0 8"/></svg><span>Customers</span></a>
            <a href="/app/reports" data-section="reports"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18M7 16v-5M12 16V7M17 16V4"/></svg><span>Reports</span></a>
            <p class="nav-caption manage-caption">MANAGE</p>
            <a href="/app/team" data-section="team"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21v-3a5 5 0 0 1 5-5h6a5 5 0 0 1 5 5v3M2 5h3M3.5 3.5v3"/></svg><span>Team & access</span></a>
            <a href="/app/activity" data-section="activity"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18H6zM9 7h6M9 11h6M9 15h3M3 7h3M3 12h3M3 17h3"/></svg><span>Activity log</span></a>
            <a href="/app/monitoring" data-section="monitoring" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12h4l3-8 6 16 3-8h4"/></svg><span>System health</span></a>
            <a href="/app/billing" data-section="billing"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 9h20M6 15h4M16 15h2"/></svg><span>Billing & plans</span></a>
            <a href="/app/settings" data-section="settings"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg><span>Settings</span></a>
        </nav>
        <div class="sidebar-bottom">
            <a class="guide-link" href="{{ route('auth.social.connections') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg><span>Sign-in accounts</span></a>
            <a class="guide-link" href="/guide"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c-3-2-7-2-10-1v15c3-1 7-1 10 1 3-2 7-2 10-1V4c-3-1-7-1-10 1Zm0 0v15"/></svg><span>The staff handbook</span></a>
            <div class="workspace-status"><i></i><span id="connection-label">Connecting to workspace</span></div>
            <form action="/logout" method="POST">@csrf<button type="submit" class="logout-button"><span id="sidebar-user">Your account</span><span>Sign out</span></button></form>
        </div>
    </aside>
    <div class="app-shell">
        <header class="topbar">
            <button class="icon-button menu-button" id="menu-button" aria-label="Open navigation" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
            <div class="breadcrumbs"><span>Workspace</span><span>/</span><strong id="breadcrumb-section">Overview</strong></div>
            <div class="topbar-tools">
                <label class="location-picker"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10h18M4 10v10h16V10M2 10l3-7h14l3 7M8 20v-6h5v6"/></svg><span class="sr-only">Active location</span><select id="location-select" aria-label="Active location"><option>Loading location…</option></select></label>
                <a class="icon-button header-help" id="context-help" href="/guide#overview" aria-label="Help with this screen"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 4.25 1.8c-1.1.7-1.75 1.2-1.75 2.7M12 17h.01"/></svg></a>
                <span class="profile-avatar" id="profile-avatar" title="Your account">S</span>
            </div>
        </header>
        <main id="main-content" tabindex="-1">
            <div id="workspace-view" aria-live="polite"><div class="loading-state"><span class="loading-line"></span><h1>A little order. A lot of possibility.</h1><p>Opening your workspace…</p></div></div>
        </main>
        <footer class="app-footer"><span>POSONLY / A clearer way to run your business.</span><a href="/guide">Need a hand? Read the guide</a></footer>
    </div>
    <dialog id="workspace-modal" aria-labelledby="modal-title"><div id="modal-content"></div></dialog>
    <div id="toast-container" class="toast-container" aria-live="polite" aria-atomic="true"></div>
    <noscript><p class="noscript-message">Please enable JavaScript to use the inventory and checkout workspace.</p></noscript>
</body>
</html>
