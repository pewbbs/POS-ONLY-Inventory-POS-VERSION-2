<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkspaceRegistrationService
{
    /** @param array{business_name:string, name:string, email:string, password:string, plan?:string} $data */
    public function create(array $data, bool $verifiedEmail = false): User
    {
        return DB::transaction(function () use ($data, $verifiedEmail): User {
            $tenant = Tenant::create(['name' => $data['business_name'], 'settings' => Tenant::defaultSettings($data['plan'] ?? 'grow')]);
            app(BillingService::class)->process($tenant);
            Location::create(['tenant_id' => $tenant->id, 'name' => 'Main store', 'type' => 'store']);
            $user = User::create(['tenant_id' => $tenant->id, 'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'role' => 'admin']);
            if ($verifiedEmail) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        }, 3);
    }
}
