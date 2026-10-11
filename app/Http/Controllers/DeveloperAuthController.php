<?php

namespace App\Http\Controllers;

use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeveloperAuthController extends Controller
{
    public function loginForm(): Response
    {
        return response()->view('developer-auth', ['mode' => 'login', 'email' => null, 'token' => null]);
    }

    public function login(Request $request): RedirectResponse
    {
        $this->canonicalizeEmail($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        if (! Auth::guard('developer')->attempt($data + ['is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        $request->session()->regenerate();

        return redirect()->route('developer', ['section' => 'overview']);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('developer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('developer.login');
    }

    public function setupForm(Request $request, string $token): Response
    {
        $queryEmail = $request->query('email');
        $email = is_string($queryEmail) ? Str::lower(trim($queryEmail)) : '';
        abort_unless($this->pendingAccount($email, $token) !== null, 404, 'This setup link is invalid or has expired.');

        return response()->view('developer-auth', ['mode' => 'setup', 'email' => $email, 'token' => $token]);
    }

    public function setup(Request $request): RedirectResponse
    {
        $this->canonicalizeEmail($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/D'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed']]);
        DB::transaction(function () use ($data): void {
            $account = $this->pendingAccount($data['email'], $data['token'], true);
            if (! $account) {
                throw ValidationException::withMessages(['email' => 'This setup link is invalid or has expired.']);
            }
            $account->update(['password' => $data['password'], 'is_active' => true, 'setup_token_hash' => null,
                'setup_expires_at' => null, 'remember_token' => Str::random(60)]);
            DB::table('developer_sessions')->where('user_id', $account->id)->delete();
            PlatformAuditLog::create(['platform_admin_id' => $account->id, 'tenant_id' => null,
                'action' => 'developer.account_activated', 'reason' => 'Private one-use account setup completed',
                'metadata' => [], 'created_at' => now()]);
        }, 5);

        return redirect()->route('developer.login')->with('status', 'Your developer account is ready. Sign in with your password.');
    }

    private function pendingAccount(string $email, string $token, bool $lock = false): ?PlatformAdmin
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }
        $query = PlatformAdmin::where('email', $email)->where('is_active', false)->whereNull('password')
            ->where('setup_token_hash', hash('sha256', $token))->where('setup_expires_at', '>', now());
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function canonicalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
    }
}
