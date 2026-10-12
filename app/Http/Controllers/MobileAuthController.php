<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class MobileAuthController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'not_regex:/\x00/'],
            'device_name' => ['required', 'string', 'max:100'],
            'abilities' => ['prohibited'], 'scopes' => ['prohibited'], 'tenant_id' => ['prohibited'],
        ]);
        $candidate = User::where('email', $data['email'])->first();
        if (! $candidate || ! Hash::check($data['password'], $candidate->password) || ! $candidate->tenant_id) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        $token = DB::transaction(function () use ($candidate, $data): NewAccessToken {
            $tenant = Tenant::whereKey($candidate->tenant_id)->lockForUpdate()->first();
            $user = User::whereKey($candidate->id)->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->first();
            if (! $user || ! $tenant || ! $user->is_active || ! Hash::check($data['password'], $user->password)
                || ! array_key_exists($user->role, config('mobile-api.abilities'))
                || ($tenant->is_demo && ! app()->environment(['local', 'testing']))) {
                throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
            }
            $user->tokens()->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
            abort_if($user->tokens()->count() >= config('mobile-api.max_devices'), 409, 'Your account has reached its device limit. Revoke an unused device before signing in.');

            return $user->createToken(trim($data['device_name']), config('mobile-api.abilities.'.$user->role), now()->addDays(config('mobile-api.token_lifetime_days')));
        }, 5);

        return response()->json([
            'token_type' => 'Bearer', 'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at->toIso8601String(),
            'abilities' => $token->accessToken->abilities,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->id;

        return response()->json(['data' => $request->user()->tokens()->orderByDesc('id')->get()->map(fn (PersonalAccessToken $token): array => [
            'id' => $token->id, 'name' => $token->name, 'current' => $token->id === $currentId,
            'created_at' => $token->created_at->toIso8601String(), 'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ])]);
    }

    public function destroy(Request $request, ?int $token = null): Response
    {
        $record = $token === null ? $request->user()->currentAccessToken() : $request->user()->tokens()->findOrFail($token);
        $record->delete();

        return response()->noContent();
    }
}
