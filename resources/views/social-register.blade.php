@php
    $providerLabel = $identity['provider'] === 'apple' ? 'Apple' : 'Google';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f3ef">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Finish your workspace — POSONLY</title>
    <link rel="icon" href="/images/favicon.svg">
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v={{ filemtime(public_path('css/auth.css')) }}">
</head>
<body>
<aside class="auth-story">
    <a class="auth-brand" href="{{ route('home') }}">POSONLY<span>.</span></a>
    <div class="story-copy">
        <p>INVENTORY / POINT OF SALE / POSSIBILITIES</p>
        <h1>LESS ADMIN.<br><span>MORE SELLING.</span></h1>
        <h2>Your next chapter starts here.</h2>
        <p>A clear view of your business.<br>A simpler workday for your team.</p>
    </div>
    <div class="story-bottom"><span>YOUR BUSINESS, IN SYNC.</span><span>↗</span></div>
    <span class="story-star" aria-hidden="true">✳</span>
</aside>
<main class="auth-main">
    <div class="auth-top"><a href="{{ route('register') }}">← Back to registration</a><a href="{{ route('guide') }}#social-sign-in">Need a hand? ↗</a></div>
    <section class="auth-card">
        <p class="eyebrow">LET’S MAKE ROOM FOR MORE.</p>
        <h2>One last thing.</h2>
        <p class="auth-subtitle">Your {{ $providerLabel }} account is verified. Add your business details to create your workspace.</p>
        <div class="verified-account">
            <strong>Verified with {{ $providerLabel }}</strong>
            <p>{{ $identity['email'] }}</p>
            @if($identity['provider'] === 'apple')<small>If you chose Hide My Email, Apple supplies a private relay address. Use the same Apple account when signing in.</small>@endif
        </div>
        @if($errors->any())<div class="form-error" role="alert"><strong>Please check these details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ route('auth.social.register.complete') }}">
            @csrf
            <input type="hidden" name="plan" value="{{ $plan }}">
            <label for="business_name">Business name</label>
            <input id="business_name" name="business_name" value="{{ old('business_name') }}" placeholder="e.g. The Corner Store" autocomplete="organization" required maxlength="100">
            <label for="name">Your name</label>
            <input id="name" name="name" value="{{ old('name', $identity['name'] ?? '') }}" placeholder="First and last name" autocomplete="name" required maxlength="100">
            <p class="social-register-note">We’ll create your <strong>Main store</strong> using <strong>PHP · Philippine peso</strong>. Review your tax rate and receipt details in <strong>Settings</strong> after signing in.</p>
            <p class="signup-note">No payment is taken when you create your workspace.</p>
            <button class="submit-button" type="submit">Create my workspace<span>↗</span></button>
        </form>
        <p class="auth-switch"><a href="{{ route('register') }}">Choose a different sign-in method ↗</a></p>
    </section>
    <footer>© {{ now()->year }} POSONLY <a href="{{ route('privacy') }}">Privacy policy</a><span>Keep your business moving.</span></footer>
</main>
</body>
</html>
