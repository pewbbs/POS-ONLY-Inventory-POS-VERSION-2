<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\SocialOAuthClient;
use App\Services\WorkspaceRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function loginForm(): View
    {
        return view('auth', ['mode' => 'login', 'socialProviders' => app(SocialOAuthClient::class)->providers(),
            'socialError' => request('social_error') === 'invalid' ? 'This sign-in could not be verified. Start again from login.' : null]);
    }

    public function registerForm(): View
    {
        return view('auth', ['mode' => 'register', 'socialProviders' => app(SocialOAuthClient::class)->providers()]);
    }

    private function canonicalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
    }

    public function login(Request $request): RedirectResponse
    {
        $this->canonicalizeEmail($request);
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::guard('web')->attempt($credentials + ['is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        if (Auth::guard('web')->user()->tenant?->is_demo && ! app()->environment(['local', 'testing'])) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['email' => 'Demo accounts are unavailable in this environment.']);
        }
        $request->session()->regenerate();

        return redirect()->intended('/app');
    }

    public function register(Request $request): RedirectResponse
    {
        $this->canonicalizeEmail($request);
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'max:255', 'confirmed'],
            'plan' => ['nullable', Rule::in(['starter', 'grow', 'scale'])],
        ]);
        $user = app(WorkspaceRegistrationService::class)->create($data);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/app');
    }

    public function demo(Request $request): RedirectResponse
    {
        abort_unless(config('stockroom.demo_enabled') && app()->environment(['local', 'testing']), 404);
        $tenant = Tenant::where('is_demo', true)->firstOrFail();
        $user = User::where('tenant_id', $tenant->id)->where('role', 'admin')->where('is_active', true)->firstOrFail();
        $data = $request->validate(['section' => ['nullable', Rule::in(['overview', 'dashboard', 'pos', 'inventory', 'reports', 'customers', 'orders', 'team', 'settings'])]]);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/app'.(! empty($data['section']) ? '/'.$data['section'] : ''));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
