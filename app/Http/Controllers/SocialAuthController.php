<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SocialAuthenticationService;
use App\Services\SocialOAuthClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SocialAuthController extends Controller
{
    public function __construct(private SocialOAuthClient $providers, private SocialAuthenticationService $accounts) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $data = $request->validate(['intent' => ['sometimes', Rule::in(['login', 'register'])], 'plan' => ['sometimes', Rule::in(array_keys(config('billing.plans')))]]);
        if (Auth::guard('web')->check()) {
            return redirect()->route('auth.social.connections');
        }

        return $this->begin($request, $provider, $data['intent'] ?? 'login', $data['plan'] ?? 'starter');
    }

    public function connect(Request $request, string $provider): RedirectResponse
    {
        return $this->begin($request, $provider, 'connect', 'starter');
    }

    private function begin(Request $request, string $provider, string $intent, string $plan): RedirectResponse
    {
        $request->session()->forget(['social_auth.flow', 'social_auth.registration']);
        $configuration = $this->providers->providers()[$provider] ?? null;
        if (! $configuration || ! $configuration['available']) {
            return $this->failure('This sign-in provider is not configured yet. Use email and password for now.', $intent === 'connect');
        }
        $actor = $intent === 'connect' ? User::with('tenant')->find($request->user('web')?->id) : null;
        if ($intent === 'connect') {
            $this->accounts->assertUserAvailable($actor);
        }
        $flow = ['provider' => $provider, 'state' => Str::random(64), 'nonce' => Str::random(64), 'code_verifier' => Str::random(64),
            'intent' => $intent, 'plan' => $plan, 'actor_id' => $actor?->id,
            'actor_password_hash' => $actor ? hash('sha256', $actor->getAuthPassword()) : null, 'expires_at' => now()->addMinutes(10)->timestamp];
        try {
            $url = $this->providers->authorizationUrl($provider, $flow['state'], $flow['nonce'], $flow['code_verifier']);
            $request->session()->put('social_auth.flow', $flow);

            return redirect()->away($url);
        } catch (Throwable) {
            return $this->failure('This sign-in provider is temporarily unavailable. Use email and password or try again.', $intent === 'connect');
        }
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        return $this->finish($request, 'google', $request->query());
    }

    /** Apple's cross-site form POST has no session middleware, preserving the original Lax cookie. */
    public function appleCallback(Request $request): RedirectResponse
    {
        $data = Validator::make($request->post(), ['state' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/D'],
            'code' => ['nullable', 'string', 'max:4096'], 'error' => ['nullable', 'string', 'max:100'], 'user' => ['nullable', 'string', 'max:8192']]);
        if ($data->fails() || ! ($this->providers->providers()['apple']['available'] ?? false)) {
            return redirect()->route('login', ['social_error' => 'invalid'], 303);
        }
        try {
            $token = Str::random(64);
            Cache::put('social-auth:apple:'.hash('sha256', $token), Crypt::encryptString(json_encode($data->validated(), JSON_THROW_ON_ERROR)), now()->addMinutes(5));

            return redirect()->route('auth.social.apple.complete', ['callback' => $token], 303);
        } catch (Throwable) {
            return redirect()->route('login', ['social_error' => 'invalid'], 303);
        }
    }

    public function appleComplete(Request $request): RedirectResponse
    {
        $token = $request->query('callback');
        if (! is_string($token) || preg_match('/^[A-Za-z0-9]{64}$/D', $token) !== 1) {
            return $this->failure('This sign-in could not be verified. Start again from login.');
        }
        try {
            $encrypted = Cache::pull('social-auth:apple:'.hash('sha256', $token));
            $payload = is_string($encrypted) ? json_decode(Crypt::decryptString($encrypted), true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $payload = null;
        }
        if (! is_array($payload)) {
            return $this->failure('This sign-in link has expired or was already used. Start again from login.');
        }

        return $this->finish($request, 'apple', $payload);
    }

    private function finish(Request $request, string $provider, array $payload): RedirectResponse
    {
        $flow = $request->session()->pull('social_auth.flow');
        $state = $payload['state'] ?? null;
        if (! is_array($flow) || ($flow['provider'] ?? null) !== $provider || ! is_string($state)
            || ! is_string($flow['state'] ?? null) || ! hash_equals($flow['state'], $state)
            || ! is_int($flow['expires_at'] ?? null) || $flow['expires_at'] <= now()->timestamp) {
            return $this->failure('This sign-in could not be verified or has expired. Start again from login.');
        }
        $connecting = ($flow['intent'] ?? null) === 'connect';
        $actor = $connecting ? User::with('tenant')->find($request->user('web')?->id) : null;
        if ($connecting && (! $actor || $actor->id !== ($flow['actor_id'] ?? null))) {
            return $this->failure('Your session changed while connecting. Start again from your account settings.', $actor !== null);
        }
        if ($connecting && (! $actor->is_active || ! $actor->tenant
            || ($actor->tenant->is_demo && ! app()->environment(['local', 'testing']))
            || ! hash_equals((string) ($flow['actor_password_hash'] ?? ''), hash('sha256', $actor->getAuthPassword())))) {
            $this->invalidateCompanySession($request);

            return $this->failure('Your account changed while connecting. Sign in again before retrying.');
        }
        if (! $connecting && Auth::guard('web')->check()) {
            return $this->failure('Your session changed. Start the sign-in again from your account settings.', true);
        }
        $code = $payload['code'] ?? null;
        if (! empty($payload['error']) || ! is_string($code) || $code === '' || strlen($code) > 4096) {
            return $this->failure('Sign-in was canceled or could not be completed. You can try again.', $connecting);
        }
        try {
            if ($connecting) {
                $this->accounts->assertUserAvailable($actor);
            }
            $identity = $this->providers->identity($provider, $code, $flow['nonce'], $flow['code_verifier']);
            if ($provider === 'apple' && ! $identity['name']) {
                $identity['name'] = $this->appleName($payload['user'] ?? null);
            }
            if ($connecting) {
                $this->accounts->connect($actor, $identity);

                return redirect()->route('auth.social.connections')->with('status', $provider === 'google' ? 'Google is connected to your account.' : 'Apple is connected to your account.');
            }
            if ($user = $this->accounts->linkedUser($identity)) {
                Auth::guard('web')->login($user);
                $request->session()->regenerate();
                $request->session()->forget('social_auth.registration');

                return redirect('/app');
            }
            $this->accounts->assertNewIdentity($identity);
            $request->session()->regenerate();
            $request->session()->put('social_auth.registration', Crypt::encryptString(json_encode(['identity' => $identity,
                'plan' => $flow['plan'], 'expires_at' => now()->addMinutes(15)->timestamp], JSON_THROW_ON_ERROR)));

            return redirect()->route('auth.social.register.form');
        } catch (ValidationException $exception) {
            if ($connecting) {
                $fresh = User::with('tenant')->find($actor->id);
                if (! $fresh || ! $fresh->is_active || ! $fresh->tenant
                    || ! hash_equals((string) $flow['actor_password_hash'], hash('sha256', $fresh->getAuthPassword()))) {
                    $this->invalidateCompanySession($request);

                    return $this->failure('Your account changed while connecting. Sign in again before retrying.');
                }
            }

            return $this->failure($exception->errors()['social_auth'][0] ?? 'This sign-in could not be completed.', $connecting);
        } catch (Throwable) {
            return $this->failure('This sign-in could not be verified. Try again or use email and password.', $connecting);
        }
    }

    private function appleName(mixed $data): ?string
    {
        if (! is_string($data) || strlen($data) > 8192) {
            return null;
        }
        try {
            $user = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
            $name = $user['name'] ?? null;
            if (! is_array($name)) {
                return null;
            }
            $parts = array_filter([$name['firstName'] ?? null, $name['lastName'] ?? null], fn ($part) => is_string($part) && mb_strlen($part) <= 100);
            $display = trim(implode(' ', $parts));

            return $display !== '' ? Str::limit($display, 100, '') : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function registerForm(Request $request): View|RedirectResponse
    {
        $pending = $this->registration($request);
        if (! $pending) {
            return $this->failure('This workspace setup has expired. Continue with Google or Apple again.');
        }

        return view('social-register', ['identity' => $pending['identity'], 'plan' => $pending['plan']]);
    }

    public function register(Request $request): RedirectResponse
    {
        $pending = $this->registration($request);
        if (! $pending) {
            return $this->failure('This workspace setup has expired. Continue with Google or Apple again.');
        }
        $data = $request->validate(['business_name' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:100'],
            'plan' => ['required', Rule::in(array_keys(config('billing.plans')))]]);
        try {
            $user = $this->accounts->register($pending['identity'], $data);
        } catch (ValidationException $exception) {
            $request->session()->forget('social_auth.registration');

            return $this->failure($exception->errors()['social_auth'][0] ?? 'Workspace setup could not be completed.');
        } catch (Throwable) {
            return $this->failure('Workspace setup is temporarily unavailable. Your account was not created. Try again.');
        }
        $request->session()->forget('social_auth.registration');
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/app');
    }

    private function registration(Request $request): ?array
    {
        $raw = $request->session()->get('social_auth.registration');
        try {
            $data = is_string($raw) ? json_decode(Crypt::decryptString($raw), true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $data = null;
        }
        if (! is_array($data) || ! is_int($data['expires_at'] ?? null) || $data['expires_at'] <= now()->timestamp) {
            $request->session()->forget('social_auth.registration');

            return null;
        }

        return $data;
    }

    public function connections(Request $request): View
    {
        $user = User::with('tenant')->findOrFail($request->user('web')->id);
        $this->accounts->assertUserAvailable($user);
        $connections = $user->socialIdentities()->get()->mapWithKeys(fn ($identity) => [$identity->provider => ['email' => $identity->email, 'created_at' => $identity->created_at]])->all();

        return view('social-connections', ['socialProviders' => $this->providers->providers(), 'connections' => $connections, 'user' => $user]);
    }

    private function failure(string $message, bool $connecting = false): RedirectResponse
    {
        return redirect()->route($connecting ? 'auth.social.connections' : 'login')->withErrors(['social_auth' => $message]);
    }

    private function invalidateCompanySession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
