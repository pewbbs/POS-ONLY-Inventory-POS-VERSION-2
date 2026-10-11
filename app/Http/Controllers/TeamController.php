<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'max:255'], 'role' => ['required', Rule::in(['admin', 'manager', 'cashier'])],
        ]);
        $user = DB::transaction(function () use ($request, $data): User {
            $tenant = Tenant::whereKey($request->user()->tenant_id)->lockForUpdate()->firstOrFail();
            $actor = User::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($actor->role === 'admin' && $actor->is_active, 403, 'Administrator access is required.');
            app(BillingService::class)->assertCapacity($tenant, 'team_members');

            return User::create(['tenant_id' => $tenant->id] + $data);
        }, 5);

        return response()->json($user->only(['id', 'name', 'email', 'role', 'is_active']), 201);
    }

    public function update(Request $request, int $member): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'role' => ['sometimes', 'required', Rule::in(['admin', 'manager', 'cashier'])],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['name' => 'Choose a name, role or access status to update.']);
        }
        $user = DB::transaction(function () use ($request, $member, $data): User {
            $tenantId = $request->user()->tenant_id;
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $actor = User::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($actor->role === 'admin' && $actor->is_active, 403, 'Administrator access is required.');
            $user = User::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($member);
            $role = $data['role'] ?? $user->role;
            $isActive = (bool) ($data['is_active'] ?? $user->is_active);
            if (! $user->is_active && $isActive) {
                app(BillingService::class)->assertCapacity($tenant, 'team_members');
            }
            if ($user->role === 'admin' && $user->is_active && ($role !== 'admin' || ! $isActive)) {
                $otherAdmin = User::where('tenant_id', $tenantId)->where('role', 'admin')->where('is_active', true)->where('id', '!=', $user->id)->exists();
                if (! $otherAdmin) {
                    throw ValidationException::withMessages(['role' => 'Keep at least one active administrator in this workspace.']);
                }
            }
            if ($user->id === $request->user()->id && ($role !== 'admin' || ! $isActive)) {
                throw ValidationException::withMessages(['is_active' => 'Ask another administrator to change your own role or pause your access.']);
            }
            if ($user->is_active && ! $isActive) {
                $data['remember_token'] = Str::random(60);
            }
            $user->forceFill($data)->save();

            return $user;
        }, 5);

        return response()->json($user->only(['id', 'name', 'email', 'role', 'is_active']));
    }
}
