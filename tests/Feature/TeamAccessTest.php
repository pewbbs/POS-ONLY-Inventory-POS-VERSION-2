<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireRole;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TeamAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function team(string $role = 'admin'): array
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $member = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'cashier']);

        return compact('tenant', 'admin', 'member');
    }

    /**
     * @param  array{role?: string, is_active?: bool}  $attributes
     */
    private function changeAccessAfterRoleCheck(User $actor, array $attributes): void
    {
        $this->app->instance(RequireRole::class, new class($actor->id, $attributes) extends RequireRole
        {
            /**
             * @param  array{role?: string, is_active?: bool}  $attributes
             */
            public function __construct(private int $actorId, private array $attributes) {}

            public function handle(Request $request, Closure $next, string ...$roles): Response
            {
                return parent::handle($request, function (Request $request) use ($next): Response {
                    User::whereKey($this->actorId)->update($this->attributes);

                    return $next($request);
                }, ...$roles);
            }
        });
    }

    public function test_team_changes_require_authentication_as_401(): void
    {
        $this->patchJson('/api/team/1', ['role' => 'manager'])->assertUnauthorized();
    }

    #[TestWith(['manager'])]
    #[TestWith(['cashier'])]
    public function test_team_changes_refuse_unprivileged_roles_as_403(string $role): void
    {
        $team = $this->team($role);
        $this->actingAs($team['admin'])->patchJson('/api/team/'.$team['member']->id, ['role' => 'admin'])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $team['member']->id, 'role' => 'cashier']);
    }

    public function test_administrator_can_edit_a_member_without_changing_sensitive_attributes(): void
    {
        $team = $this->team();
        $password = $team['member']->password;
        $email = $team['member']->email;
        $this->actingAs($team['admin'])->patchJson('/api/team/'.$team['member']->id, ['name' => 'Sam Lee', 'role' => 'manager', 'is_active' => true, 'tenant_id' => 999, 'email' => 'changed@example.test', 'password' => 'unrequested-password'])
            ->assertOk()->assertJsonPath('name', 'Sam Lee')->assertJsonPath('role', 'manager')->assertJsonPath('is_active', true)->assertJsonMissing(['password' => 'unrequested-password']);
        $member = $team['member']->refresh();
        $this->assertSame($team['tenant']->id, $member->tenant_id);
        $this->assertSame($password, $member->password);
        $this->assertSame($email, $member->email);
    }

    public function test_paused_member_loses_existing_session_access_as_403(): void
    {
        $team = $this->team();
        $rememberToken = $team['member']->remember_token;
        $this->actingAs($team['admin'])->patchJson('/api/team/'.$team['member']->id, ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $member = $team['member']->refresh();
        $this->assertNotSame($rememberToken, $member->remember_token);
        $this->actingAs($member)->getJson('/api/bootstrap')->assertForbidden()->assertJsonPath('message', 'Your workspace access is paused. Ask an administrator to reactivate your account.');
        $this->assertGuest();
    }

    public function test_paused_member_with_a_cached_identity_cannot_read_sales_as_403(): void
    {
        Http::preventStrayRequests();
        $team = $this->team();
        $this->actingAs($team['member']);
        User::whereKey($team['member']->id)->update(['is_active' => false]);

        $this->getJson('/api/sales')->assertForbidden()
            ->assertJsonPath('message', 'Your workspace access is paused. Ask an administrator to reactivate your account.');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_deleted_member_with_a_cached_identity_cannot_read_sales_as_403(): void
    {
        Http::preventStrayRequests();
        $team = $this->team();
        $this->actingAs($team['member']);
        $team['member']->delete();

        $this->getJson('/api/sales')->assertForbidden()
            ->assertJsonPath('message', 'Your account has no active workspace.');

        Http::assertNothingSent();
    }

    #[TestWith(['manager'])]
    #[TestWith(['cashier'])]
    public function test_demoted_administrator_with_a_cached_identity_cannot_read_activity_as_403(string $role): void
    {
        Http::preventStrayRequests();
        $team = $this->team();
        $this->actingAs($team['admin']);
        User::whereKey($team['admin']->id)->update(['role' => $role]);

        $this->getJson('/api/activity')->assertForbidden()
            ->assertJsonPath('message', 'Your role does not have access to this action.');

        Http::assertNothingSent();
    }

    public function test_bootstrap_uses_a_demoted_cashiers_current_role_and_redactions(): void
    {
        Http::preventStrayRequests();
        $team = $this->team();
        $this->actingAs($team['admin']);
        User::whereKey($team['admin']->id)->update(['role' => 'cashier']);

        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.role', 'cashier')
            ->assertJsonPath('team', [])->assertJsonPath('stats.inventory_value', null);

        Http::assertNothingSent();
    }

    public function test_administrator_can_reactivate_a_paused_member(): void
    {
        $team = $this->team();
        $team['member']->update(['is_active' => false]);
        $this->actingAs($team['admin'])->patchJson('/api/team/'.$team['member']->id, ['is_active' => true])->assertOk()->assertJsonPath('is_active', true);
        $this->actingAs($team['member']->refresh())->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.is_active', true);
    }

    public function test_last_active_administrator_cannot_be_demoted_as_422(): void
    {
        $team = $this->team();
        $this->actingAs($team['admin'])->patchJson('/api/team/'.$team['admin']->id, ['role' => 'cashier'])
            ->assertUnprocessable()->assertJsonValidationErrors('role')->assertJsonPath('errors.role.0', 'Keep at least one active administrator in this workspace.');
        $this->assertDatabaseHas('users', ['id' => $team['admin']->id, 'role' => 'admin', 'is_active' => true]);
    }

    public function test_administrator_cannot_pause_self_even_with_another_active_administrator_as_422(): void
    {
        $team = $this->team();
        User::factory()->create(['tenant_id' => $team['tenant']->id, 'role' => 'admin']);
        $this->actingAs($team['admin'])->patchJson('/api/team/'.$team['admin']->id, ['is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active')->assertJsonPath('errors.is_active.0', 'Ask another administrator to change your own role or pause your access.');
        $this->assertDatabaseHas('users', ['id' => $team['admin']->id, 'is_active' => true]);
    }

    public function test_an_administrator_can_pause_another_administrator_when_access_is_preserved(): void
    {
        $team = $this->team();
        $otherAdmin = User::factory()->create(['tenant_id' => $team['tenant']->id, 'role' => 'admin']);
        $this->actingAs($team['admin'])->patchJson('/api/team/'.$otherAdmin->id, ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id, 'is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $team['admin']->id, 'is_active' => true, 'role' => 'admin']);
    }

    public function test_an_administrator_can_update_own_name_without_losing_access(): void
    {
        $team = $this->team();
        $this->actingAs($team['admin'])->patchJson('/api/team/'.$team['admin']->id, ['name' => 'Alex Rivera'])->assertOk()->assertJsonPath('name', 'Alex Rivera');
        $this->assertDatabaseHas('users', ['id' => $team['admin']->id, 'name' => 'Alex Rivera', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_team_update_for_another_tenant_is_404(): void
    {
        $team = $this->team();
        $otherTeam = $this->team();
        $this->actingAs($team['admin'])->patchJson('/api/team/'.$otherTeam['member']->id, ['role' => 'admin'])->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $otherTeam['member']->id, 'role' => 'cashier']);
    }

    public function test_empty_and_invalid_role_updates_are_422(): void
    {
        $team = $this->team();
        $this->actingAs($team['admin'])->patchJson('/api/team/'.$team['member']->id, [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/api/team/'.$team['member']->id, ['role' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseHas('users', ['id' => $team['member']->id, 'role' => 'cashier']);
    }

    public function test_team_creation_normalizes_email_before_checking_uniqueness(): void
    {
        $team = $this->team();
        $this->actingAs($team['admin'])->postJson('/api/team', ['name' => 'Morgan', 'email' => '  MORGAN@EXAMPLE.TEST  ', 'password' => 'initial-password-123', 'role' => 'cashier'])->assertCreated()->assertJsonPath('email', 'morgan@example.test')->assertJsonPath('is_active', true);
        $this->postJson('/api/team', ['name' => 'Another Morgan', 'email' => 'MORGAN@EXAMPLE.TEST', 'password' => 'initial-password-123', 'role' => 'manager'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_access_changed_during_a_request_is_rechecked_before_updating_a_member_as_403(): void
    {
        $team = $this->team();
        $this->changeAccessAfterRoleCheck($team['admin'], ['role' => 'cashier']);
        $this->actingAs($team['admin'])->patchJson('/api/team/'.$team['member']->id, ['role' => 'admin'])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $team['member']->id, 'role' => 'cashier']);
    }

    #[TestWith(['role', 'manager'])]
    #[TestWith(['role', 'cashier'])]
    #[TestWith(['is_active', false])]
    public function test_access_changed_during_a_request_is_rechecked_before_creating_a_member_as_403(string $field, mixed $value): void
    {
        $this->freezeTime();
        $team = $this->team();
        $subscription = Subscription::factory()->create(['tenant_id' => $team['tenant']->id]);
        $subscriptionBefore = $subscription->fresh()->getAttributes();
        $this->changeAccessAfterRoleCheck($team['admin'], [$field => $value]);

        $this->actingAs($team['admin'])->postJson('/api/team', [
            'name' => 'New cashier', 'email' => 'new-cashier@example.test',
            'password' => 'initial-password-123', 'role' => 'cashier',
        ])->assertForbidden()->assertJsonPath('message', 'Administrator access is required.');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseMissing('users', ['email' => 'new-cashier@example.test']);
        $this->assertSame($subscriptionBefore, $subscription->fresh()->getAttributes());
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
