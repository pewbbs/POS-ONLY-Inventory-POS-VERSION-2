<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f3ef">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Connected sign-in accounts — POSONLY</title>
    <link rel="icon" href="/images/favicon.svg">
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v={{ filemtime(public_path('css/auth.css')) }}">
</head>
<body>
<aside class="auth-story">
    <a class="auth-brand" href="{{ route('home') }}">POSONLY<span>.</span></a>
    <div class="story-copy">
        <p>INVENTORY / POINT OF SALE / POSSIBILITIES</p>
        <h1>LESS ADMIN.<br><span>MORE SELLING.</span></h1>
        <h2>Your business. Your way in.</h2>
        <p>A clear view of your business.<br>A simpler workday for your team.</p>
    </div>
    <div class="story-bottom"><span>YOUR BUSINESS, IN SYNC.</span><span>↗</span></div>
    <span class="story-star" aria-hidden="true">✳</span>
</aside>
<main class="auth-main">
    <div class="auth-top"><a href="{{ route('workspace', ['section' => 'overview']) }}">← Back to workspace</a><a href="{{ route('guide') }}#social-sign-in">Need a hand? ↗</a></div>
    <section class="auth-card">
        <p class="eyebrow">YOUR ACCOUNT / SIGN-IN OPTIONS</p>
        <h2>A familiar way in.</h2>
        <p class="auth-subtitle">Connect a Google or Apple account to sign in to your existing workspace.</p>
        <div class="verified-account"><strong>{{ $user->name }}</strong><p>{{ $user->email }}</p><small>Connections apply to your own company account.</small></div>
        @if($errors->any())<div class="form-error" role="alert"><strong>Please check these details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(session('status'))<div class="form-notice" role="status">{{ session('status') }}</div>@endif
        <div class="social-providers" aria-label="Connect a sign-in account">
            @foreach($socialProviders as $provider => $details)
                @if(isset($connections[$provider]))
                    <div class="connected-provider"><strong>{{ $details['label'] }} connected <span>✓</span></strong><p>{{ $connections[$provider]['email'] }}</p></div>
                @else
                    <form method="POST" action="{{ route('auth.social.connect', ['provider' => $provider]) }}">
                        @csrf
                        <button class="social-provider" type="submit" @unless($details['available']) disabled aria-describedby="{{ $provider }}-connection-note" @endunless>
                            @if($provider === 'google')
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.4-.18-2.06H12v3.9h5.38a4.6 4.6 0 0 1-2 3.02v2.5h3.24c1.9-1.75 2.98-4.33 2.98-7.36Z"/><path fill="#34A853" d="M12 22c2.7 0 4.96-.9 6.62-2.42l-3.24-2.5c-.9.6-2.04.97-3.38.97-2.6 0-4.81-1.76-5.6-4.13H3.06v2.58A10 10 0 0 0 12 22Z"/><path fill="#FBBC05" d="M6.4 13.92a6 6 0 0 1 0-3.84V7.5H3.06a10 10 0 0 0 0 9l3.34-2.58Z"/><path fill="#EA4335" d="M12 5.95c1.47 0 2.79.51 3.83 1.52l2.87-2.87A9.64 9.64 0 0 0 12 2a10 10 0 0 0-8.94 5.5l3.34 2.58C7.19 7.71 9.4 5.95 12 5.95Z"/></svg>
                            @else
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.05 12.54c.03 3.3 2.9 4.4 2.93 4.41-.03.08-.46 1.57-1.51 3.1-.9 1.32-1.83 2.63-3.3 2.66-1.45.03-1.92-.86-3.58-.86s-2.19.83-3.56.89c-1.42.05-2.5-1.43-3.42-2.74-1.86-2.69-3.28-7.61-1.37-10.93a5.32 5.32 0 0 1 4.48-2.73c1.4-.03 2.73.94 3.59.94.85 0 2.46-1.16 4.14-.99.7.03 2.69.28 3.96 2.15-.1.07-2.37 1.38-2.34 4.1ZM14.33 4.49c.75-.91 1.26-2.18 1.12-3.44-1.08.04-2.38.72-3.15 1.62-.69.79-1.3 2.06-1.14 3.28 1.2.1 2.42-.61 3.17-1.46Z"/></svg>
                            @endif
                            <span>Connect {{ $details['label'] }}</span>
                            @unless($details['available'])<span class="provider-status">Setup required</span>@endunless
                        </button>
                    </form>
                    @unless($details['available'])<p class="social-setup-note" id="{{ $provider }}-connection-note">{{ $details['message'] }}</p>@endunless
                @endif
            @endforeach
        </div>
        <p class="social-register-note">Only connect accounts you own. The connected provider opens this same workspace with your current role. It does not grant developer access.</p>
        <p class="auth-switch"><a href="{{ route('workspace', ['section' => 'overview']) }}">Back to my workspace ↗</a></p>
    </section>
    <footer>© {{ now()->year }} POSONLY <span>Keep your business moving.</span></footer>
</main>
</body>
</html>
