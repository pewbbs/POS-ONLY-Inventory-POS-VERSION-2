@php
    $companySignedIn = auth('web')->check();
    $workspaceUrl = route('workspace', ['section' => 'overview'], false);
    $actionUrl = $companySignedIn ? $workspaceUrl : route('register', [], false);
    $actionLabel = $companySignedIn ? 'Back to workspace' : 'Create your workspace';
@endphp
<!DOCTYPE html>
<html lang="en-PH">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.public-metadata', ['seo' => $seo, 'seoTitle' => $page['title'], 'seoDescription' => $page['description'], 'seoHome' => false])
    <meta name="theme-color" content="#f5f3ef">
    <link rel="preload" href="/fonts/anton-400.ttf" as="font" type="font/ttf" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}?v={{ filemtime(public_path('css/landing.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/marketing.css') }}?v={{ filemtime(public_path('css/marketing.css')) }}">
</head>
<body class="marketing-page">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header marketing-header">
    <a class="wordmark" href="{{ route('home', [], false) }}" aria-label="POSONLY home"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>POSONLY<span class="brand-period">.</span></a>
    <nav class="marketing-nav" aria-label="Product navigation">
        <a href="{{ route('marketing.inventory', [], false) }}" @if($pageKey === 'inventory') aria-current="page" @endif>Inventory</a>
        <a href="{{ route('marketing.pos', [], false) }}" @if($pageKey === 'pos') aria-current="page" @endif>Point of sale</a>
        <a href="{{ route('home', [], false) }}#pricing">Pricing</a>
        <a href="{{ route('guide', [], false) }}">User guide</a>
    </nav>
    <div class="header-actions"><a class="button button-dark button-small" href="{{ $actionUrl }}">{{ $actionLabel }}</a></div>
</header>
<main id="main">
    <section class="marketing-hero" aria-labelledby="page-title">
        <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('home', [], false) }}">Home</a><span aria-hidden="true">/</span><span aria-current="page">{{ $page['label'] }}</span></nav>
        <p class="eyebrow"><span class="status-dot" aria-hidden="true"></span> {{ $page['kicker'] }}</p>
        <h1 id="page-title">{{ $page['label'] }}<span class="rust">{{ $pageKey === 'about' ? 'An easier workday.' : 'For Philippine businesses.' }}</span></h1>
        <p class="marketing-intro">{{ $page['intro'] }}</p>
        <div class="marketing-highlights">@foreach($page['highlights'] as $highlight)<span><b aria-hidden="true">✓</b> {{ $highlight }}</span>@endforeach</div>
        <div class="marketing-actions"><a class="button button-rust" href="{{ $actionUrl }}">{{ $actionLabel }}</a><a class="text-button" href="{{ route('guide', [], false) }}">Read the staff guide</a></div>
    </section>
    <div class="marketing-layout">
        <aside class="marketing-contents">
            <p class="eyebrow">ON THIS PAGE</p>
            <nav aria-label="Page contents">@foreach($page['sections'] as $section)<a href="#{{ $section['id'] }}">{{ $section['title'] }}</a>@endforeach<a href="#plans">Plans & pricing</a><a href="#questions">Common questions</a></nav>
        </aside>
        <div class="marketing-articles">
            @foreach($page['sections'] as $section)
            <section class="marketing-section" id="{{ $section['id'] }}" aria-labelledby="{{ $section['id'] }}-heading">
                <p class="eyebrow">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} / {{ $page['label'] }}</p>
                <h2 id="{{ $section['id'] }}-heading">{{ $section['title'] }}</h2>
                <p>{{ $section['text'] }}</p>
                <ul>@foreach($section['items'] as $item)<li>{{ $item }}</li>@endforeach</ul>
                <a class="marketing-text-link" href="{{ route('guide', [], false) }}#{{ $section['guide'] }}">{{ $section['guide_label'] }}</a>
            </section>
            @endforeach
            <section class="marketing-example" aria-labelledby="example-heading">
                <p class="eyebrow">A PRACTICAL EXAMPLE</p><h2 id="example-heading">{{ $page['example_title'] }}</h2><p>{{ $page['example'] }}</p>
            </section>
            <section class="marketing-section" id="plans" aria-labelledby="plans-heading">
                <p class="eyebrow">PRICES IN PHILIPPINE PESOS</p><h2 id="plans-heading">Choose a plan for your team.</h2>
                <p>New workspaces include a {{ config('billing.trial_days') }}-day trial. No card is required to register. Monthly and annual plans include inventory, POS, branded receipts and reports; location and active staff limits vary.</p>
                <div class="marketing-table-wrap" role="region" aria-label="Plan comparison" tabindex="0">
                    <table class="marketing-table"><caption>POSONLY subscription plans</caption><thead><tr><th scope="col">Plan</th><th scope="col">Monthly</th><th scope="col">Annual total</th><th scope="col">Locations</th><th scope="col">Active staff</th></tr></thead><tbody>
                    @foreach($plans as $plan)<tr><th scope="row">{{ $plan['name'] }}</th><td>₱{{ number_format($plan['monthly_cents'] / 100, 2) }}</td><td>₱{{ number_format($plan['annual_cents'] / 100, 2) }}</td><td>{{ $plan['locations'] }}</td><td>{{ $plan['team_members'] ?? 'Unlimited' }}</td></tr>@endforeach
                    </tbody></table>
                </div>
                <p class="marketing-small">Annual pricing is one invoice for the annual total shown. Renewal invoices are paid individually; POSONLY does not automatically debit a card or wallet. Hardware and separate customer payment services are not included.</p>
                <a class="marketing-text-link" href="{{ route('home', [], false) }}#pricing">Explore the pricing options</a>
            </section>
            <section class="marketing-section" id="questions" aria-labelledby="questions-heading">
                <p class="eyebrow">CLEAR ANSWERS BEFORE YOU START</p><h2 id="questions-heading">Common questions.</h2>
                <div class="faq-list">@foreach($page['questions'] as $question)<details><summary>{{ $question['question'] }} <span aria-hidden="true">+</span></summary><p>{{ $question['answer'] }}</p></details>@endforeach</div>
            </section>
            @if($pageKey === 'about')
            <section class="marketing-contact" aria-labelledby="contact-heading"><p class="eyebrow">OPERATOR & PRIVACY CONTACT</p><h2 id="contact-heading">A clear point of contact.</h2><p>Pablito Torres, trading as POSONLY.<br>Online inventory and POS software for Philippine businesses.</p><a class="marketing-text-link" href="mailto:pewbbs@gmail.com">pewbbs@gmail.com</a><p class="marketing-small">For privacy requests, read <a href="{{ route('privacy', [], false) }}">how POSONLY handles your information</a>. @if($site['support_email'])For product support, contact <a href="mailto:{{ $site['support_email'] }}">{{ $site['support_email'] }}</a>.@elseFor everyday tasks and troubleshooting, start with the <a href="{{ route('guide', [], false) }}">user guide</a>.@endif</p></section>
            @endif
            <section class="marketing-next" aria-labelledby="next-heading"><div><p class="eyebrow">KEEP EXPLORING</p><h2 id="next-heading">See how it works together.</h2></div><nav aria-label="Related product pages"><a href="{{ route('marketing.inventory', [], false) }}">Inventory management</a><a href="{{ route('marketing.pos', [], false) }}">Point of sale</a><a href="{{ route('marketing.about', [], false) }}">About POSONLY</a></nav></section>
        </div>
    </div>
    <section class="marketing-closing"><p class="eyebrow">YOUR NEXT WORKDAY, A LITTLE SIMPLER.</p><h2>Start with one workspace.</h2><a class="button button-rust" href="{{ $actionUrl }}">{{ $actionLabel }}</a></section>
</main>
<footer class="site-footer marketing-footer"><a class="wordmark" href="{{ route('home', [], false) }}">POSONLY<span class="brand-period">.</span></a><p>© {{ date('Y') }} POSONLY · ONLINE SERVICE</p><nav aria-label="Footer"><a href="{{ route('marketing.about', [], false) }}">About</a><a href="{{ route('guide', [], false) }}">User guide</a><a href="{{ route('privacy', [], false) }}">Privacy</a><a href="{{ route('developer', ['section' => 'overview'], false) }}">Developer portal</a>@include('partials.public-social-links', ['officialProfiles' => (array) config('seo.official_profiles', [])])</nav></footer>
</body>
</html>
