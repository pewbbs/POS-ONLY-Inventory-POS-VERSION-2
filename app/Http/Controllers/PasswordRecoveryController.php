<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordRecoveryController extends Controller
{
    public const LINK_REQUESTED = 'If an eligible account matches this address, a password reset link will be sent. Check your inbox, or ask your administrator if it does not arrive.';

    public const INVALID_LINK = 'This password reset link is invalid or has expired. Request a new link and try again.';

    private function canonicalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
    }

    private function eligibleCredentials(array $data): array
    {
        return $data + [
            'is_active' => true,
            'eligible_workspace' => fn (Builder $query): Builder => $query->whereHas('tenant', fn (Builder $tenant): Builder => $tenant->where('is_demo', false)),
        ];
    }

    public function requestForm(): View
    {
        return view('auth', ['mode' => 'forgot']);
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $this->canonicalizeEmail($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        try {
            Password::sendResetLink($this->eligibleCredentials($data));
        } catch (TransportExceptionInterface $exception) {
            Log::error('Password recovery email delivery failed.', ['exception_type' => $exception::class]);
        }

        return redirect()->route('password.request')->with('status', self::LINK_REQUESTED);
    }

    public function resetForm(Request $request, string $token): Response
    {
        return response()->view('auth', ['mode' => 'reset', 'token' => $token, 'email' => is_string($request->query('email')) ? $request->query('email') : ''])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->canonicalizeEmail($request);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'], 'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:10', 'max:255', 'confirmed'],
        ]);
        $resetAccount = null;
        $status = DB::transaction(function () use ($data, &$resetAccount): string {
            $brokerName = config('auth.defaults.passwords');
            DB::table(config('auth.passwords.'.$brokerName.'.table'))->where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($this->eligibleCredentials($data), function (User $user, string $password) use (&$resetAccount): void {
                $account = User::whereKey($user->id)->where('is_active', true)->whereHas('tenant', fn (Builder $tenant): Builder => $tenant->where('is_demo', false))->lockForUpdate()->first();
                if (! $account) {
                    throw ValidationException::withMessages(['email' => self::INVALID_LINK]);
                }
                $account->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $account->id)->delete();
                $resetAccount = $account;
            });
        }, 5);
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => self::INVALID_LINK]);
        }

        event(new PasswordReset($resetAccount));

        return redirect()->route('login')->with('status', 'Your password has been reset. Sign in with your new password.');
    }
}
