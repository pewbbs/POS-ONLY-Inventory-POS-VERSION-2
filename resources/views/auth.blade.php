@php
    $mode = $mode ?? 'login';
    $copy = [
        'login' => ['title' => 'Log in', 'heading' => 'Log in to POSONLY', 'subtitle' => 'Access your company workspace.', 'button' => 'Log in to POSONLY', 'route' => 'login'],
        'register' => ['title' => 'Create your workspace', 'heading' => 'Create your workspace', 'subtitle' => 'Set up your business and administrator account.', 'button' => 'Create my workspace', 'route' => 'register'],
        'forgot' => ['title' => 'Request a password reset', 'heading' => 'Request a password reset', 'subtitle' => 'Enter your account email address to receive a reset link.', 'button' => 'Send reset link', 'route' => 'password.email'],
        'reset' => ['title' => 'Reset your password', 'heading' => 'Reset your password', 'subtitle' => 'Choose a new password for your account.', 'button' => 'Save new password', 'route' => 'password.update'],
    ][$mode];
    $newPassword = in_array($mode, ['register', 'reset'], true);
    $socialProviders = $socialProviders ?? [];
    $socialPlan = in_array(request('plan'), ['starter', 'grow', 'scale'], true) ? request('plan') : 'starter';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f3ef">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $copy['title'] }} — POSONLY</title>
    <link rel="icon" href="/images/favicon.svg">
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v={{ filemtime(public_path('css/auth.css')) }}">
</head>
<body>
<a class="skip-link" href="#auth-main">Skip to the form</a>
<aside class="auth-story">
    <a class="auth-brand" href="{{ route('home') }}" aria-label="POSONLY home">POSONLY<span aria-hidden="true">.</span></a>
    <div class="story-copy">
        <h2>Inventory and point of sale</h2>
        <p>Track stock and process sales in one workspace.</p>
    </div>
</aside>
<main class="auth-main" id="auth-main" tabindex="-1">
    <div class="auth-top"><a href="{{ route('home') }}">Back to home</a><a href="{{ route('guide') }}">User guide</a></div>
    <section class="auth-card" aria-labelledby="auth-title">
        <h1 id="auth-title">{{ $copy['heading'] }}</h1>
        <p class="auth-subtitle">{{ $copy['subtitle'] }}</p>
        @if($errors->any())
            <div class="form-error" role="alert">
                <strong>Check the details below.</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                @if($mode === 'reset')<a class="recovery-link" href="{{ route('password.request') }}">Request a new reset link</a>@endif
            </div>
        @endif
        @if($socialError ?? null)<div class="form-error" role="alert">{{ $socialError }}</div>@endif
        @if(session('status'))<div class="form-notice" role="status">{{ session('status') }}</div>@endif
        @if(in_array($mode, ['login', 'register'], true) && count($socialProviders))
            <div class="social-providers" aria-label="Continue with your account">
                @foreach($socialProviders as $provider => $details)
                    @if($details['available'])
                        <a class="social-provider" href="{{ route('auth.social.redirect', ['provider' => $provider, 'intent' => $mode === 'register' ? 'register' : 'login', 'plan' => $socialPlan]) }}">
                    @else
                        <button class="social-provider" type="button" disabled aria-describedby="{{ $provider }}-setup-note">
                    @endif
                        @if($provider === 'google')
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.4-.18-2.06H12v3.9h5.38a4.6 4.6 0 0 1-2 3.02v2.5h3.24c1.9-1.75 2.98-4.33 2.98-7.36Z"/><path fill="#34A853" d="M12 22c2.7 0 4.96-.9 6.62-2.42l-3.24-2.5c-.9.6-2.04.97-3.38.97-2.6 0-4.81-1.76-5.6-4.13H3.06v2.58A10 10 0 0 0 12 22Z"/><path fill="#FBBC05" d="M6.4 13.92a6 6 0 0 1 0-3.84V7.5H3.06a10 10 0 0 0 0 9l3.34-2.58Z"/><path fill="#EA4335" d="M12 5.95c1.47 0 2.79.51 3.83 1.52l2.87-2.87A9.64 9.64 0 0 0 12 2a10 10 0 0 0-8.94 5.5l3.34 2.58C7.19 7.71 9.4 5.95 12 5.95Z"/></svg>
                        @else
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.05 12.54c.03 3.3 2.9 4.4 2.93 4.41-.03.08-.46 1.57-1.51 3.1-.9 1.32-1.83 2.63-3.3 2.66-1.45.03-1.92-.86-3.58-.86s-2.19.83-3.56.89c-1.42.05-2.5-1.43-3.42-2.74-1.86-2.69-3.28-7.61-1.37-10.93a5.32 5.32 0 0 1 4.48-2.73c1.4-.03 2.73.94 3.59.94.85 0 2.46-1.16 4.14-.99.7.03 2.69.28 3.96 2.15-.1.07-2.37 1.38-2.34 4.1ZM14.33 4.49c.75-.91 1.26-2.18 1.12-3.44-1.08.04-2.38.72-3.15 1.62-.69.79-1.3 2.06-1.14 3.28 1.2.1 2.42-.61 3.17-1.46Z"/></svg>
                        @endif
                        <span>Continue with {{ $details['label'] }}</span>
                        @unless($details['available'])<span class="provider-status">Setup required</span>@endunless
                    @if($details['available'])</a>@else</button>@endif
                @endforeach
            </div>
            @foreach($socialProviders as $provider => $details)
                @unless($details['available'])<p class="social-setup-note" id="{{ $provider }}-setup-note">{{ $details['message'] }}</p>@endunless
            @endforeach
            <div class="auth-divider"><span>or use email</span></div>
        @endif
        <form method="POST" action="{{ route($copy['route']) }}">
            @csrf
            @if($mode === 'reset')<input type="hidden" name="token" value="{{ $token }}">@endif
            @if($mode === 'register')
                <label for="business_name">Business name</label>
                <input id="business_name" name="business_name" value="{{ old('business_name') }}" placeholder="e.g. The Corner Store" autocomplete="organization" required maxlength="100">
                <label for="name">Your name</label>
                <input id="name" name="name" value="{{ old('name') }}" placeholder="First and last name" autocomplete="name" required maxlength="100">
                <input type="hidden" name="plan" value="{{ in_array(request('plan'), ['starter', 'grow', 'scale'], true) ? request('plan') : 'starter' }}">
            @endif
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" value="{{ old('email', $email ?? '') }}" placeholder="you@yourbusiness.com" autocomplete="email" required maxlength="255" @if($mode === 'reset') readonly @endif>
            @if($mode !== 'forgot')
                <div class="password-label">
                    <label for="password">{{ $mode === 'reset' ? 'New password' : 'Password' }}</label>
                    @if($mode === 'login')<a href="{{ route('password.request') }}">Forgot password?</a>@endif
                </div>
                <div class="password-input">
                    <input type="password" id="password" name="password" placeholder="{{ $newPassword ? 'At least 10 characters' : 'Your password' }}" autocomplete="{{ $newPassword ? 'new-password' : 'current-password' }}" required maxlength="255" @if($newPassword) minlength="10" @endif>
                    <button class="show-password" type="button" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
            @endif
            @if($newPassword)
                <label for="password_confirmation">Confirm {{ $mode === 'reset' ? 'new password' : 'password' }}</label>
                <input type="password" id="password_confirmation" name="password_confirmation" placeholder="Confirm your password" autocomplete="new-password" required minlength="10" maxlength="255">
            @endif
            @if($mode === 'register')<p class="signup-note">Start with a {{ config('billing.trial_days') }}-day free trial. Creating your workspace does not collect payment.</p>@endif
            @if($mode === 'forgot')<p class="recovery-note">Links expire in {{ config('auth.passwords.'.config('auth.defaults.passwords').'.expire') }} minutes. If your account is paused, ask your administrator for help.</p>@endif
            @if($mode === 'reset')<p class="recovery-note">Saving your new password signs you out on other devices. Sign in again to continue.</p>@endif
            <button class="submit-button" type="submit">{{ $copy['button'] }}</button>
        </form>
        @if(in_array($mode, ['forgot', 'reset'], true))
            <p class="auth-switch"><a href="{{ route('login') }}">Back to log in</a></p>
        @else
            <p class="auth-switch">{{ $mode === 'register' ? 'Already have a workspace?' : 'Need a workspace?' }} <a href="{{ route($mode === 'register' ? 'login' : 'register') }}">{{ $mode === 'register' ? 'Log in' : 'Create a workspace' }}</a></p>
            @if(config('stockroom.demo_enabled') && app()->environment(['local', 'testing']))
                <div class="demo-divider"><span>Try the sample store</span></div>
                <form action="{{ route('demo') }}" method="POST">@csrf<button class="demo-button" type="submit">Explore the sample store</button></form>
            @endif
        @endif
    </section>
    <footer>© {{ now()->year }} POSONLY <a href="{{ route('privacy') }}">Privacy policy</a></footer>
</main>
<script>
    document.querySelector('.show-password')?.addEventListener('click', function () {
        const field = document.querySelector('#password');
        const show = field.type === 'password';
        field.type = show ? 'text' : 'password';
        this.textContent = show ? 'Hide' : 'Show';
        this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        this.setAttribute('aria-pressed', String(show));
    });
</script>
</body>
</html>
