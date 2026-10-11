<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#191918">
    <meta name="robots" content="noindex, nofollow">
    <title>Developer portal — POSONLY</title>
    <link rel="icon" href="/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('css/developer.css') }}?v={{ filemtime(public_path('css/developer.css')) }}">
    <script src="{{ asset('js/developer.js') }}?v={{ filemtime(public_path('js/developer.js')) }}" defer></script>
</head>
<body data-developer-section="{{ $section ?? request()->segment(2, 'overview') }}">
    <a class="skip-link" href="#developer-main">Skip to developer portal</a>
    <div class="sidebar-shade" id="developer-shade"></div>
    <aside class="sidebar" id="developer-sidebar" aria-label="Developer navigation">
        <a class="brand" href="{{ route('home') }}" aria-label="POSONLY home"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span>POSONLY<span class="brand-period">.</span></span></a>
        <div class="portal-identity"><span class="identity-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 7-5 5 5 5m8-10 5 5-5 5m-3-13-2 16"/></svg></span><div><strong>Developer portal</strong><span>Platform administration</span></div></div>
        <p class="nav-caption">THE BIG PICTURE</p>
        <nav id="developer-nav">
            <a href="{{ route('developer', ['section' => 'overview']) }}" data-view="overview"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></svg><span>Overview</span></a>
            <a href="{{ route('developer', ['section' => 'companies']) }}" data-view="companies"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21V7l9-4v18M12 10h9v11M2 21h20M6 9h2M6 13h2M6 17h2M15 14h3M15 18h3"/></svg><span>Companies</span></a>
            <a href="{{ route('developer', ['section' => 'billing']) }}" data-view="billing"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 9h20M6 15h4M16 15h2"/></svg><span>Subscriptions & invoices</span></a>
            <p class="nav-caption manage-caption">MANAGE</p>
            <a href="{{ route('developer', ['section' => 'content']) }}" data-view="content"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 8h18M7 5.5h.01M10 5.5h.01M7 12h10M7 16h6"/></svg><span>Website content</span></a>
            <a href="{{ route('developer', ['section' => 'activity']) }}" data-view="activity"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18H6zM9 7h6M9 11h6M9 15h3M3 7h3M3 17h3"/></svg><span>Platform activity</span></a>
        </nav>
        <div class="sidebar-bottom">
            <a class="sidebar-link" href="{{ route('home') }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18"/></svg><span>View public website</span><span>↗</span></a>
            <a class="sidebar-link" href="/app"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10h18M4 10v10h16V10M2 10l3-7h14l3 7M8 20v-6h5v6"/></svg><span>Company workspace</span><span>↗</span></a>
            <div class="connection-status" id="developer-connection"><i></i><span>Connecting to platform</span></div>
            <form action="{{ route('developer.logout') }}" method="POST">@csrf<button type="submit" class="logout-button"><span id="developer-name">Developer account</span><span>Sign out ↗</span></button></form>
        </div>
    </aside>
    <div class="app-shell">
        <header class="topbar">
            <button class="icon-button menu-button" id="developer-menu" aria-label="Open navigation" aria-expanded="false" aria-controls="developer-sidebar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
            <div class="breadcrumbs"><span>Developer</span><span>/</span><strong id="developer-breadcrumb">Overview</strong></div>
            <div class="topbar-tools"><span class="platform-label">PLATFORM OWNER</span><span class="profile-avatar" id="developer-avatar" title="Developer account">D</span></div>
        </header>
        <main id="developer-main" tabindex="-1"><div id="developer-view" aria-live="polite"><div class="loading-state"><span class="loading-line"></span><h1>A clear view of every business.</h1><p>Opening the developer portal…</p></div></div></main>
        <footer class="app-footer"><span>POSONLY / Developer portal</span><span>Company data stays in its own workspace.</span></footer>
    </div>
    <dialog id="developer-modal" aria-labelledby="developer-modal-title"><div id="developer-modal-content"></div></dialog>
    <div id="developer-toasts" class="toast-container" aria-live="polite" aria-atomic="true"></div>
    <noscript><p class="noscript-message">Please enable JavaScript to use the developer portal.</p></noscript>
</body>
</html>
