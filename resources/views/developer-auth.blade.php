@php
    $mode = $mode ?? 'login';
    $isSetup = $mode === 'setup';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#191918">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $isSetup ? 'Set up developer access' : 'Developer sign in' }} — POSONLY</title>
    <link rel="icon" href="/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('css/developer.css') }}?v={{ filemtime(public_path('css/developer.css')) }}">
</head>
<body class="developer-auth">
    <aside class="auth-story">
        <a class="auth-brand" href="{{ route('home') }}">POSONLY<span>.</span></a>
        <div class="story-copy"><p>THE PLATFORM / THE PEOPLE / THE POSSIBILITIES</p><h1>A LITTLE<br>OVERSIGHT.<br><span>A LOT OF CLARITY.</span></h1><p>Manage your companies.<br>Keep the whole picture in view.</p></div>
        <div class="story-bottom"><span>DEVELOPER ACCESS</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 7-5 5 5 5m8-10 5 5-5 5m-3-13-2 16"/></svg></div>
    </aside>
    <main class="auth-main">
        <div class="auth-top"><a href="{{ route('home') }}">← Back to website</a><a href="{{ route('login') }}">Company sign in ↗</a></div>
        <section class="auth-card">
            <p class="eyebrow">POSONLY / DEVELOPER PORTAL</p>
            <h2>{{ $isSetup ? 'Make it your own.' : 'The whole picture.' }}</h2>
            <p class="auth-subtitle">{{ $isSetup ? 'Choose your private password to activate developer access.' : 'Sign in with your platform owner account to manage companies and website content.' }}</p>
            @if($errors->any())<div class="form-error" role="alert"><strong>Please check these details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @if(session('status'))<div class="form-notice" role="status">{{ session('status') }}</div>@endif
            <form method="POST" action="{{ route($isSetup ? 'developer.setup.store' : 'developer.login') }}">
                @csrf
                @if($isSetup)<input type="hidden" name="token" value="{{ $token }}">@endif
                <label for="developer-email">Developer email</label>
                <input type="email" name="email" id="developer-email" value="{{ $isSetup ? ($email ?? '') : old('email') }}" autocomplete="username" maxlength="255" required @if($isSetup) readonly @endif>
                <label for="developer-password">{{ $isSetup ? 'New password' : 'Password' }}</label>
                <div class="password-input"><input type="password" name="password" id="developer-password" autocomplete="{{ $isSetup ? 'new-password' : 'current-password' }}" placeholder="{{ $isSetup ? 'At least 12 characters' : 'Your private password' }}" required maxlength="255" @if($isSetup) minlength="12" @endif><button type="button" id="developer-show-password" aria-pressed="false" aria-label="Show password">Show</button></div>
                @if($isSetup)<label for="developer-confirm-password">Confirm password</label><input type="password" name="password_confirmation" id="developer-confirm-password" autocomplete="new-password" minlength="12" maxlength="255" required><p class="auth-note">This setup link activates your developer account. Keep your password private.</p>@endif
                <button class="submit-button" type="submit">{{ $isSetup ? 'Activate developer access' : 'Sign in to developer portal' }}<span>↗</span></button>
            </form>
            <p class="auth-separation">Developer access is separate from company sign in. Your existing company session stays available.</p>
        </section>
        <footer>© {{ now()->year }} POSONLY <span>A clearer way to run your platform.</span></footer>
    </main>
    <script>
        document.querySelector('#developer-show-password')?.addEventListener('click', function () {
            const input = document.querySelector('#developer-password');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            this.textContent = show ? 'Hide' : 'Show';
            this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            this.setAttribute('aria-pressed', String(show));
        });
    </script>
</body>
</html>
