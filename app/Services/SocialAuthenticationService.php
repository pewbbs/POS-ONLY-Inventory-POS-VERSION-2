<?php

namespace App\Services;

use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialAuthenticationService
{
    public function __construct(private WorkspaceRegistrationService $registration) {}

    public function linkedUser(array $identity): ?User
    {
        $link = $this->identityQuery($identity)->first();
        if (! $link) {
            return null;
        }
        $user = User::with('tenant')->find($link->user_id);
        $this->assertUserAvailable($user);

        return $user;
    }

    public function assertUserAvailable(?User $user): void
    {
        if (! $user || ! $user->is_active || ! $user->tenant || ($user->tenant->is_demo && ! app()->environment(['local', 'testing']))) {
            throw ValidationException::withMessages(['social_auth' => 'This account is unavailable. Ask your company administrator for help.']);
        }
    }

    public function assertNewIdentity(array $identity): void
    {
        $email = $identity['email'] ?? null;
        if (! ($identity['email_verified'] ?? false) || ! is_string($email)
            || Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            throw ValidationException::withMessages(['social_auth' => 'A verified email is required to create a workspace. Try another account or use email registration.']);
        }
        if (User::whereRaw('LOWER(email) = ?', [Str::lower($email)])->exists()) {
            throw ValidationException::withMessages(['social_auth' => 'An account already uses this email. Sign in with its password, then open Sign-in accounts to connect Google or Apple.']);
        }
    }

    public function register(array $identity, array $data): User
    {
        return DB::transaction(function () use ($identity, $data): User {
            $this->assertNewIdentity($identity);
            if ($this->identityQuery($identity)->exists()) {
                throw ValidationException::withMessages(['social_auth' => 'This sign-in is already connected. Return to login and continue with that provider.']);
            }
            $user = $this->registration->create(['business_name' => $data['business_name'], 'name' => $data['name'],
                'email' => Str::lower($identity['email']), 'password' => Str::random(64), 'plan' => $data['plan']], true);
            SocialIdentity::create($this->linkData($identity) + ['user_id' => $user->id]);

            return $user;
        }, 3);
    }

    public function connect(User $actor, array $identity): void
    {
        DB::transaction(function () use ($actor, $identity): void {
            $fresh = User::with('tenant')->whereKey($actor->id)->lockForUpdate()->first();
            $this->assertUserAvailable($fresh);
            if (! hash_equals($actor->getAuthPassword(), $fresh->getAuthPassword())) {
                throw ValidationException::withMessages(['social_auth' => 'Your account changed while connecting. Sign in again before retrying.']);
            }
            $existing = $this->identityQuery($identity)->lockForUpdate()->first();
            $ownProvider = SocialIdentity::where('user_id', $fresh->id)->where('provider', $identity['provider'])->lockForUpdate()->first();
            if (($existing && $existing->user_id !== $fresh->id) || ($ownProvider && $ownProvider->subject_hash !== hash('sha256', $identity['subject']))) {
                throw ValidationException::withMessages(['social_auth' => 'This provider account cannot be connected. Use the account already linked or choose another sign-in.']);
            }
            if (! $existing) {
                SocialIdentity::create($this->linkData($identity) + ['user_id' => $fresh->id]);
            }
        }, 3);
    }

    private function identityQuery(array $identity): Builder
    {
        return SocialIdentity::where('provider', $identity['provider'])->where('subject_hash', hash('sha256', $identity['subject']));
    }

    private function linkData(array $identity): array
    {
        return ['provider' => $identity['provider'], 'subject_hash' => hash('sha256', $identity['subject']),
            'email' => ($identity['email_verified'] ?? false) ? ($identity['email'] ?? null) : null];
    }
}
