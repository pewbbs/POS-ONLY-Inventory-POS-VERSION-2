<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\PlatformAdmin;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DeveloperPlatformService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PayMongoBillingEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn (): string => 'testing');
        parent::tearDown();
    }

    private function configurePayMongo(): void
    {
        config([
            'billing.payment_provider' => 'paymongo', 'paymongo.enabled' => true, 'paymongo.mode' => 'test',
            'paymongo.secret_key' => 'sk_test_endpointfixture', 'paymongo.webhook_secret' => '',
            'paymongo.payment_methods' => ['card', 'gcash', 'qrph'], 'app.url' => 'http://127.0.0.1:8010',
        ]);
        Http::preventStrayRequests();
    }

    /** @return array{user: User, invoice: BillingInvoice, subscription: Subscription} */
    private function billing(string $role = 'admin'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id]);
        $invoice = BillingInvoice::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id]);

        return compact('user', 'invoice', 'subscription');
    }

    private function configureLive(): void
    {
        $this->configurePayMongo();
        config(['paymongo.mode' => 'live', 'paymongo.secret_key' => 'sk_live_endpointfixture',
            'paymongo.webhook_secret' => 'whsec_live_endpointfixture', 'paymongo.payment_methods' => ['qrph'],
            'app.url' => 'https://posonly.example']);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->withSession(['_token' => 'live-csrf-fixture'])->withHeader('X-CSRF-TOKEN', 'live-csrf-fixture');
    }

    public function test_live_qr_ph_checkout_and_server_confirmation_preserve_live_provenance_and_neutral_activity_keys(): void
    {
        $this->freezeSecond();
        $store = $this->billing();
        $records = $this->operationalRecords();
        $this->configureLive();
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => fn (Request $request) => Http::response(
            $this->pendingSession($request['data']['attributes']['reference_number'], true),
        )]);
        $path = '/api/billing/invoices/'.$store['invoice']->id;

        $response = $this->actingAs($store['user'])->postJson($path.'/paymongo-checkout')->assertOk()
            ->assertJsonPath('attempt.mode', 'live')->assertJsonPath('attempt.status', 'pending')
            ->assertJsonPath('billing.payment_config.mode', 'live')->assertJsonPath('billing.payment_config.payment_methods', ['qrph']);
        $resource = $this->pendingSession($response->json('attempt.reference'), true);
        $resource['data']['attributes']['payments'] = [['id' => 'pay_live_endpoint', 'type' => 'payment', 'attributes' => [
            'status' => 'paid', 'livemode' => true, 'amount' => 99900, 'currency' => 'PHP',
            'paid_at' => now()->timestamp, 'source' => ['type' => 'qrph'], 'refunds' => [],
        ]]];
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_endpoint' => Http::response($resource)]);

        $checked = $this->postJson($path.'/paymongo-recheck')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('attempt.status', 'paid')->assertJsonPath('attempt.mode', 'live')
            ->assertJsonPath('billing.invoices.0.payment_source', 'paymongo_live')
            ->assertJsonPath('billing.invoices.0.payment_mode', 'live');
        $this->assertStringNotContainsString('sk_live_', $checked->getContent());
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $store['user']->tenant_id, 'action' => 'subscription.checkout_requested']);
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $store['user']->tenant_id, 'action' => 'subscription.payment_checked']);
        $this->getJson('/api/activity?action=subscription.checkout_requested')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.action', 'subscription.checkout_requested');
        $csv = $this->get('/api/billing/invoices/export')->assertOk()->streamedContent();
        $rows = array_map(fn (string $row): array => str_getcsv($row, ',', '"', ''), explode("\n", trim($csv)));
        $this->assertSame('paymongo', $rows[1][10]);
        $this->assertSame('live', $rows[1][11]);
        $this->assertSame('pay_live_endpoint', $rows[1][14]);
        $this->assertSame($records, $this->operationalRecords());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public function test_live_recovery_uses_get_only_and_records_neutral_recovery_action(): void
    {
        $store = $this->billing();
        $attempt = $this->unresolvedAttempt($store['invoice']);
        $attempt->update(['mode' => 'live', 'checkout_payload' => ['data' => ['attributes' => ['payment_method_types' => ['qrph']]]]]);
        $this->configureLive();
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_endpoint' => Http::response($this->pendingSession($attempt->reference, true))]);

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recover', ['session_id' => 'cs_endpoint'])
            ->assertOk()->assertJsonPath('attempt.id', $attempt->id)->assertJsonPath('attempt.mode', 'live')->assertJsonPath('attempt.status', 'pending');

        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $store['user']->tenant_id, 'action' => 'subscription.checkout_recovered']);
        $this->getJson('/api/activity?action=subscription.checkout_recovered')->assertOk()->assertJsonPath('meta.total', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public function test_live_recheck_without_an_attempt_uses_neutral_activity_and_never_contacts_provider(): void
    {
        $store = $this->billing();
        $this->configureLive();

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recheck')
            ->assertOk()->assertJsonPath('attempt', null)->assertJsonPath('checkout_url', null);

        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $store['user']->tenant_id, 'action' => 'subscription.payment_checked']);
        $this->assertDatabaseMissing('activity_logs', ['tenant_id' => $store['user']->tenant_id, 'action' => 'subscription.test_payment_checked']);
        $this->assertDatabaseCount('billing_payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_live_checkout_without_a_signing_secret_is_disabled_before_a_provider_request_or_record_creation(): void
    {
        $store = $this->billing();
        $this->configureLive();
        config(['paymongo.webhook_secret' => '']);

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-checkout')
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->getJson('/api/billing')->assertOk()->assertJsonPath('payment_config.checkout_enabled', false)
            ->assertJsonPath('payment_config.webhook_configured', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('billing_payment_attempts', 0);
        $this->assertSame('open', $store['invoice']->refresh()->status);
    }

    public function test_developer_totals_and_billing_history_keep_live_test_and_simulation_records_separate(): void
    {
        $store = $this->billing();
        $developer = PlatformAdmin::factory()->create();
        $store['invoice']->update(['status' => 'paid', 'payment_provider' => 'paymongo', 'payment_mode' => 'live', 'payment_method' => 'qrph']);
        $test = BillingInvoice::factory()->create(['tenant_id' => $store['user']->tenant_id, 'subscription_id' => $store['subscription']->id,
            'status' => 'paid', 'amount_cents' => 199900, 'payment_provider' => 'paymongo', 'payment_mode' => 'test', 'payment_method' => 'card']);
        $simulation = BillingInvoice::factory()->create(['tenant_id' => $store['user']->tenant_id, 'subscription_id' => $store['subscription']->id,
            'status' => 'paid', 'amount_cents' => 29900, 'payment_provider' => 'simulation', 'payment_mode' => 'simulation', 'payment_method' => 'simulation']);
        $this->configureLive();
        $service = app(DeveloperPlatformService::class);

        $overview = $service->bootstrap($developer, $service->filters(\Illuminate\Http\Request::create('/api/developer/bootstrap')));
        $billing = $this->actingAs($store['user'])->getJson('/api/billing')->assertOk();
        $sources = collect($billing->json('invoices'))->keyBy('id');

        $this->assertSame(999, $overview['overview']['paid_paymongo_live_total']);
        $this->assertSame(1999, $overview['overview']['paid_paymongo_test_total']);
        $this->assertSame(299, $overview['overview']['paid_simulation_total']);
        $this->assertSame('paymongo_live', $sources[$store['invoice']->id]['payment_source']);
        $this->assertSame('paymongo_test', $sources[$test->id]['payment_source']);
        $this->assertSame('simulation', $sources[$simulation->id]['payment_source']);
        Http::assertNothingSent();
    }

    public function test_guests_cannot_start_or_recheck_provider_checkout(): void
    {
        $this->configurePayMongo();

        $this->postJson('/api/billing/invoices/1/paymongo-checkout')->assertUnauthorized();
        $this->postJson('/api/billing/invoices/1/paymongo-recheck')->assertUnauthorized();
        $this->postJson('/api/billing/invoices/1/paymongo-recover', ['session_id' => 'cs_endpoint'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    #[TestWith(['manager'])]
    #[TestWith(['cashier'])]
    public function test_non_administrators_cannot_start_or_recheck_provider_checkout(string $role): void
    {
        $this->configurePayMongo();
        $store = $this->billing($role);
        $id = $store['invoice']->id;

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$id.'/paymongo-checkout')->assertForbidden();
        $this->postJson('/api/billing/invoices/'.$id.'/paymongo-recheck')->assertForbidden();
        $this->postJson('/api/billing/invoices/'.$id.'/paymongo-recover', ['session_id' => 'cs_endpoint'])->assertForbidden();
        $this->getJson('/api/billing/invoices/export')->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('billing_payment_attempts', 0);
    }

    #[TestWith(['role', 'manager'])]
    #[TestWith(['is_active', false])]
    public function test_current_access_is_rechecked_before_a_provider_request(string $field, mixed $value): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        User::query()->whereKey($store['user']->id)->update([$field => $value]);
        $id = $store['invoice']->id;

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$id.'/paymongo-checkout')->assertForbidden();
        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$id.'/paymongo-recheck')->assertForbidden();
        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$id.'/paymongo-recover', ['session_id' => 'cs_endpoint'])->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('billing_payment_attempts', 0);
        $this->assertSame('open', $store['invoice']->refresh()->status);
    }

    public function test_foreign_invoices_are_404_without_provider_requests_or_record_changes(): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        $foreign = $this->billing();
        $before = $foreign['invoice']->refresh()->getAttributes();
        $id = $foreign['invoice']->id;

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$id.'/paymongo-checkout')->assertNotFound();
        $this->postJson('/api/billing/invoices/'.$id.'/paymongo-recheck')->assertNotFound();
        $this->postJson('/api/billing/invoices/'.$id.'/paymongo-recover', ['session_id' => 'cs_endpoint'])->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame($before, $foreign['invoice']->refresh()->getAttributes());
        $this->assertDatabaseCount('billing_payment_attempts', 0);
    }

    public function test_provider_invoice_routes_reject_non_numeric_identifiers(): void
    {
        $this->configurePayMongo();
        $store = $this->billing();

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/not-an-invoice/paymongo-checkout')->assertNotFound();
        $this->postJson('/api/billing/invoices/not-an-invoice/paymongo-recheck')->assertNotFound();
        $this->postJson('/api/billing/invoices/not-an-invoice/paymongo-recover', ['session_id' => 'cs_endpoint'])->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('billing_payment_attempts', 0);
    }

    #[TestWith(['paymongo.enabled', false, 404])]
    #[TestWith(['paymongo.mode', 'live', 404])]
    #[TestWith(['paymongo.secret_key', 'sk_live_endpointfixture', 404])]
    #[TestWith(['billing.payment_provider', 'simulation', 404])]
    #[TestWith(['paymongo.secret_key', '', 422])]
    public function test_disabled_or_unsafe_provider_configuration_never_contacts_paymongo(string $key, mixed $value, int $status): void
    {
        $this->configurePayMongo();
        config([$key => $value]);
        $store = $this->billing();
        $id = $store['invoice']->id;

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$id.'/paymongo-checkout')->assertStatus($status);
        $this->postJson('/api/billing/invoices/'.$id.'/paymongo-recheck')->assertStatus($status);
        $this->postJson('/api/billing/invoices/'.$id.'/paymongo-recover', ['session_id' => 'cs_endpoint'])->assertStatus($status);

        Http::assertNothingSent();
        $this->assertDatabaseCount('billing_payment_attempts', 0);
        $this->assertSame('open', $store['invoice']->refresh()->status);
    }

    public function test_application_return_url_with_userinfo_is_refused_without_exposing_it(): void
    {
        $this->configurePayMongo();
        config(['app.url' => 'https://private-name:private-password@app.invalid']);
        $store = $this->billing();

        $response = $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-checkout')
            ->assertUnprocessable()->assertJsonValidationErrors('payment');

        $this->assertStringNotContainsString('private-name', $response->getContent());
        $this->assertStringNotContainsString('private-password', $response->getContent());
        Http::assertNothingSent();
        $this->assertDatabaseCount('billing_payment_attempts', 0);
    }

    public function test_checkout_uses_the_server_invoice_quote_and_ignores_injected_ownership_amounts_and_urls(): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        $foreign = $this->billing();
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => fn (Request $request) => Http::response(
            $this->pendingSession($request['data']['attributes']['reference_number']),
        )]);

        $response = $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-checkout', [
            'tenant_id' => $foreign['user']->tenant_id, 'invoice_id' => $foreign['invoice']->id, 'user_id' => $foreign['user']->id,
            'amount' => 1, 'currency' => 'USD', 'mode' => 'live', 'status' => 'paid',
            'success_url' => 'https://attacker.invalid', 'cancel_url' => 'https://attacker.invalid', 'provider_url' => 'https://attacker.invalid',
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('attempt.status', 'pending')->assertJsonPath('attempt.invoice_id', $store['invoice']->id)
            ->assertJsonPath('attempt.amount', 999)->assertJsonPath('attempt.mode', 'test')
            ->assertJsonPath('checkout_url', 'https://checkout.paymongo.com/endpoint-fixture');
        $reference = $response->json('attempt.reference');
        $id = $store['invoice']->id;

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_endpointfixture:'))
            && $request->hasHeader('Idempotency-Key', $reference)
            && $request['data']['attributes']['line_items'][0]['amount'] === 99900
            && $request['data']['attributes']['line_items'][0]['currency'] === 'PHP'
            && $request['data']['attributes']['line_items'][0]['quantity'] === 1
            && $request['data']['attributes']['payment_method_types'] === ['card', 'gcash']
            && $request['data']['attributes']['success_url'] === 'http://127.0.0.1:8010/app/billing?paymongo_return=success&invoice='.$id
            && $request['data']['attributes']['cancel_url'] === 'http://127.0.0.1:8010/app/billing?paymongo_return=cancel&invoice='.$id);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('billing_payment_attempts', [
            'id' => $response->json('attempt.id'), 'tenant_id' => $store['user']->tenant_id,
            'billing_invoice_id' => $id, 'amount_cents' => 99900, 'currency' => 'PHP', 'mode' => 'test', 'status' => 'pending',
        ]);
        $this->assertSame('open', $store['invoice']->refresh()->status);
        $this->assertNull($store['subscription']->refresh()->current_period_end);
        $this->assertStringNotContainsString('sk_test_endpointfixture', $response->getContent());
        $this->assertStringNotContainsString('checkout.paymongo.com', json_encode($response->json('billing'), JSON_THROW_ON_ERROR));
        $raw = BillingPaymentAttempt::findOrFail($response->json('attempt.id'))->getRawOriginal('checkout_url');
        $this->assertStringNotContainsString('https://checkout.paymongo.com/endpoint-fixture', $raw);
    }

    public function test_recheck_uses_the_stored_tenant_session_and_ignores_foreign_session_claims(): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        $attempt = BillingPaymentAttempt::factory()->create([
            'billing_invoice_id' => $store['invoice']->id, 'status' => 'pending', 'session_id' => 'cs_endpoint',
            'checkout_payload' => ['data' => ['attributes' => ['payment_method_types' => ['card']]]],
        ]);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_endpoint' => Http::response($this->pendingSession($attempt->reference))]);

        $response = $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recheck', [
            'session_id' => 'cs_foreign', 'reference' => 'foreign-reference', 'status' => 'paid', 'amount' => 1,
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('attempt.id', $attempt->id)->assertJsonPath('attempt.status', 'pending');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/cs_endpoint');
        Http::assertSentCount(1);
        $this->assertSame('open', $store['invoice']->refresh()->status);
        $this->assertNull($store['subscription']->refresh()->current_period_end);
        $this->assertSame('cs_endpoint', $response->json('attempt.session_id'));
    }

    public function test_provider_error_bodies_never_leak_through_checkout_or_billing_history(): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::response([
            'errors' => [['detail' => 'provider-secret-fixture sk_test_endpointfixture /private/logs email=private@example.invalid']],
        ], 500)]);

        $response = $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-checkout')->assertOk()
            ->assertJsonPath('attempt.status', 'unknown')->assertJsonPath('checkout_url', null);
        $history = $this->getJson('/api/billing')->assertOk();

        foreach ([$response->getContent(), $history->getContent()] as $content) {
            $this->assertStringNotContainsString('provider-secret-fixture', $content);
            $this->assertStringNotContainsString('sk_test_endpointfixture', $content);
            $this->assertStringNotContainsString('/private/logs', $content);
            $this->assertStringNotContainsString('private@example.invalid', $content);
        }
        Http::assertSentCount(1);
        $this->assertSame('open', $store['invoice']->refresh()->status);
        $this->assertNull($store['subscription']->refresh()->current_period_end);
    }

    public function test_expired_subscription_can_start_and_recheck_test_checkout_without_changing_stock_sales_or_drawers(): void
    {
        $this->configurePayMongo();
        $this->freezeTime();
        $store = $this->billing();
        $store['subscription']->update([
            'status' => 'active', 'current_period_start' => now()->subDays(40), 'current_period_end' => now()->subDays(10),
        ]);
        Stock::factory()->create(['tenant_id' => $store['user']->tenant_id, 'quantity' => 20, 'reserved_quantity' => 3]);
        $before = $this->operationalRecords();
        Http::fake([
            'https://api.paymongo.com/v2/checkout_sessions' => fn (Request $request) => Http::response($this->pendingSession($request['data']['attributes']['reference_number'])),
            'https://api.paymongo.com/v1/checkout_sessions/cs_endpoint' => fn () => Http::response($this->pendingSession(BillingPaymentAttempt::firstOrFail()->reference)),
        ]);

        $response = $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-checkout')->assertOk()
            ->assertJsonPath('billing.subscription.can_write', false)->assertJsonPath('attempt.status', 'pending');
        $this->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recheck')->assertOk()
            ->assertJsonPath('attempt.id', $response->json('attempt.id'))->assertJsonPath('attempt.status', 'pending');

        $this->assertSame($before, $this->operationalRecords());
        $this->assertSame('open', $store['invoice']->refresh()->status);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('billing_payment_attempts', 1);
    }

    public function test_billing_history_keeps_tenant_test_records_public_but_hides_checkout_secrets(): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        $attempt = BillingPaymentAttempt::factory()->create([
            'billing_invoice_id' => $store['invoice']->id, 'status' => 'pending',
            'checkout_url' => 'https://checkout.paymongo.com/hidden-checkout-secret', 'checkout_payload' => ['secret' => 'hidden-payload-secret'],
        ]);
        $foreign = BillingPaymentAttempt::factory()->create();

        $response = $this->actingAs($store['user'])->getJson('/api/billing?tenant_id='.$foreign->tenant_id)->assertOk()
            ->assertJsonPath('payment_config.provider', 'paymongo')->assertJsonPath('payment_config.mode', 'test')
            ->assertJsonPath('payment_attempt_total', 1)->assertJsonPath('payment_attempts.0.id', $attempt->id)
            ->assertJsonPath('invoices.0.latest_attempt.id', $attempt->id);

        $this->assertEqualsCanonicalizing([
            'id', 'invoice_id', 'status', 'reference', 'session_id', 'payment_id', 'amount', 'currency', 'mode', 'created_at', 'confirmed_at', 'failure_summary',
        ], array_keys($response->json('payment_attempts.0')));
        $this->assertStringNotContainsString('hidden-checkout-secret', $response->getContent());
        $this->assertStringNotContainsString('hidden-payload-secret', $response->getContent());
        $this->assertStringNotContainsString('sk_test_endpointfixture', $response->getContent());
        $this->assertStringNotContainsString($foreign->reference, $response->getContent());
        Http::assertNothingSent();
    }

    public function test_recheck_returns_verified_test_payment_labels_and_repeated_confirmation_does_not_extend_access(): void
    {
        $this->configurePayMongo();
        $this->freezeTime();
        $store = $this->billing();
        $attempt = BillingPaymentAttempt::factory()->create([
            'billing_invoice_id' => $store['invoice']->id, 'status' => 'pending', 'session_id' => 'cs_endpoint',
            'checkout_payload' => ['data' => ['attributes' => ['payment_method_types' => ['card']]]],
        ]);
        $session = $this->pendingSession($attempt->reference);
        $session['data']['attributes']['payments'] = [[
            'id' => 'pay_endpoint', 'type' => 'payment', 'attributes' => [
                'status' => 'paid', 'amount' => 99900, 'currency' => 'PHP', 'livemode' => false,
                'paid_at' => now()->timestamp, 'source' => ['type' => 'card'], 'refunds' => [],
            ],
        ]];
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_endpoint' => Http::response($session)]);

        $response = $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recheck')->assertOk()
            ->assertJsonPath('attempt.status', 'paid')->assertJsonPath('attempt.payment_id', 'pay_endpoint')->assertJsonPath('checkout_url', null)
            ->assertJsonPath('billing.subscription.status', 'active')->assertJsonPath('billing.invoices.0.payment_provider', 'paymongo')
            ->assertJsonPath('billing.invoices.0.payment_mode', 'test')->assertJsonPath('billing.invoices.0.payment_source', 'paymongo_test');
        $end = $response->json('billing.subscription.current_period_end');
        $this->travel(1)->days();
        $this->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recheck')->assertOk()
            ->assertJsonPath('attempt.status', 'paid')->assertJsonPath('billing.subscription.current_period_end', $end);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('billing_invoices', ['id' => $store['invoice']->id, 'status' => 'paid', 'payment_provider' => 'paymongo', 'payment_mode' => 'test']);
        $this->assertDatabaseCount('billing_payment_attempts', 1);
    }

    public function test_out_of_range_provider_payment_time_keeps_recheck_available_without_changing_subscription_or_invoice(): void
    {
        $this->configurePayMongo();
        $this->freezeSecond();
        $store = $this->billing();
        $attempt = $this->unresolvedAttempt($store['invoice']);
        $attempt->update(['status' => 'pending', 'session_id' => 'cs_endpoint']);
        $invoiceBefore = $store['invoice']->refresh()->getAttributes();
        $subscriptionBefore = $store['subscription']->refresh()->getAttributes();
        $resource = $this->pendingSession($attempt->reference);
        $resource['data']['attributes']['payments'] = [['id' => 'pay_invalid_time', 'type' => 'payment', 'attributes' => [
            'status' => 'paid', 'livemode' => false, 'amount' => 99900, 'currency' => 'PHP',
            'paid_at' => PHP_INT_MAX, 'source' => ['type' => 'card'], 'refunds' => [],
        ]]];
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_endpoint' => Http::response($resource)]);

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recheck')
            ->assertOk()->assertJsonPath('attempt.status', 'unknown')->assertJsonPath('checkout_url', null)
            ->assertJsonPath('billing.invoices.0.status', 'open');

        $this->assertSame($invoiceBefore, $store['invoice']->refresh()->getAttributes());
        $this->assertSame($subscriptionBefore, $store['subscription']->refresh()->getAttributes());
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $attempt->id, 'session_id' => 'cs_endpoint', 'payment_id' => null, 'confirmed_at' => null]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public function test_invoice_csv_covers_older_history_and_uses_verified_payment_references_instead_of_a_newer_failed_attempt(): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        $store['invoice']->update([
            'status' => 'paid', 'payment_provider' => 'paymongo', 'payment_mode' => 'test', 'payment_method' => 'card', 'payment_key' => 'paymongo:pay_confirmed',
        ]);
        $paid = BillingPaymentAttempt::factory()->create([
            'billing_invoice_id' => $store['invoice']->id, 'status' => 'paid', 'active_scope' => null, 'session_id' => 'cs_confirmed', 'payment_id' => 'pay_confirmed',
            'checkout_url' => 'https://checkout.paymongo.com/hidden-checkout-secret', 'checkout_payload' => ['secret' => 'hidden-payload-secret'],
        ]);
        $failed = BillingPaymentAttempt::factory()->create([
            'billing_invoice_id' => $store['invoice']->id, 'status' => 'failed', 'active_scope' => null, 'session_id' => 'cs_laterfailed',
        ]);
        $legacy = BillingInvoice::factory()->create([
            'tenant_id' => $store['user']->tenant_id, 'subscription_id' => $store['subscription']->id, 'status' => 'paid', 'payment_method' => 'simulation',
        ]);
        BillingInvoice::factory()->count(101)->create([
            'tenant_id' => $store['user']->tenant_id, 'subscription_id' => $store['subscription']->id, 'status' => 'void',
        ]);
        $foreign = BillingInvoice::factory()->create();

        $history = $this->actingAs($store['user'])->getJson('/api/billing')->assertOk()->assertJsonPath('invoice_total', 103)->assertJsonCount(100, 'invoices');
        $this->assertNotContains($store['invoice']->id, array_column($history->json('invoices'), 'id'));
        $response = $this->getJson('/api/billing/invoices/export')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $content = $response->streamedContent();
        $lines = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim($content)));
        $header = array_shift($lines);
        $rows = array_map(fn (array $row): array => array_combine($header, $row), $lines);
        $confirmed = array_values(array_filter($rows, fn (array $row): bool => $row['Invoice'] === $store['invoice']->number))[0];
        $simulation = array_values(array_filter($rows, fn (array $row): bool => $row['Invoice'] === $legacy->number))[0];

        $this->assertCount(103, $rows);
        $this->assertSame('paymongo', $confirmed['Provider']);
        $this->assertSame('test', $confirmed['Mode']);
        $this->assertSame($paid->reference, $confirmed['Checkout Reference']);
        $this->assertSame('cs_confirmed', $confirmed['Checkout Session']);
        $this->assertSame('pay_confirmed', $confirmed['Payment Reference']);
        $this->assertSame('simulation', $simulation['Provider']);
        $this->assertSame('simulation', $simulation['Mode']);
        foreach ([$foreign->number, $failed->reference, 'hidden-checkout-secret', 'hidden-payload-secret', 'sk_test_endpointfixture'] as $private) {
            $this->assertStringNotContainsString($private, $content);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('billing_invoices', 104);
    }

    #[TestWith([[], 'The session id field is required.'])]
    #[TestWith([['session_id' => 'https://attacker.invalid/cs_endpoint'], 'The session id field format is invalid.'])]
    public function test_recovery_validates_a_session_identifier_before_contacting_the_provider(array $payload, string $message): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        $attempt = $this->unresolvedAttempt($store['invoice']);
        $before = $attempt->getAttributes();

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recover', $payload)
            ->assertUnprocessable()->assertInvalid(['session_id' => $message]);

        Http::assertNothingSent();
        $this->assertSame($before, $attempt->refresh()->getAttributes());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_recovery_refuses_injected_payment_and_ownership_fields_instead_of_using_them(): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        $attempt = $this->unresolvedAttempt($store['invoice']);
        $before = $attempt->getAttributes();

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recover', [
            'session_id' => 'cs_endpoint', 'tenant_id' => 999999, 'amount' => 1, 'currency' => 'USD', 'status' => 'paid',
            'reference' => 'attacker-reference', 'checkout_url' => 'https://attacker.invalid',
        ])->assertUnprocessable()->assertInvalid(['session_id' => 'Provide only the checkout session ID.']);

        Http::assertNothingSent();
        $this->assertSame($before, $attempt->refresh()->getAttributes());
        $this->assertSame('open', $store['invoice']->refresh()->status);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_recovery_verifies_an_existing_session_with_get_and_records_only_safe_audit_metadata(): void
    {
        $this->configurePayMongo();
        $this->freezeTime();
        $store = $this->billing();
        $store['subscription']->update(['status' => 'active', 'current_period_end' => now()->subDays(10)]);
        $attempt = $this->unresolvedAttempt($store['invoice']);
        Stock::factory()->create(['tenant_id' => $store['user']->tenant_id, 'quantity' => 20, 'reserved_quantity' => 3]);
        $before = $this->operationalRecords();
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_endpoint' => Http::response($this->pendingSession($attempt->reference))]);

        $response = $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recover', [
            'session_id' => 'cs_endpoint', '_token' => 'framework-token-fixture',
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('attempt.id', $attempt->id)->assertJsonPath('attempt.invoice_id', $store['invoice']->id)
            ->assertJsonPath('attempt.status', 'pending')->assertJsonPath('attempt.session_id', 'cs_endpoint')
            ->assertJsonPath('checkout_url', 'https://checkout.paymongo.com/endpoint-fixture')
            ->assertJsonPath('billing.subscription.can_write', false);

        $this->assertEqualsCanonicalizing(['attempt', 'checkout_url', 'message', 'billing'], array_keys($response->json()));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/cs_endpoint'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_endpointfixture:')));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $attempt->id, 'session_id' => 'cs_endpoint', 'status' => 'pending']);
        $this->assertSame('open', $store['invoice']->refresh()->status);
        $this->assertSame($before, $this->operationalRecords());
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $store['user']->tenant_id, 'actor_id' => $store['user']->id,
            'action' => 'subscription.test_checkout_recovered', 'resource_type' => 'billing_invoice', 'resource_id' => $store['invoice']->id,
        ]);
        $this->assertDatabaseCount('activity_logs', 1);
        $history = json_encode($response->json('billing'), JSON_THROW_ON_ERROR);
        $audit = ActivityLog::firstOrFail()->toJson();
        foreach (['checkout.paymongo.com', 'sk_test_endpointfixture', 'framework-token-fixture'] as $private) {
            $this->assertStringNotContainsString($private, $history);
            $this->assertStringNotContainsString($private, $audit);
        }
        $this->assertStringNotContainsString('cs_endpoint', $audit);
    }

    public function test_uncertain_recovery_does_not_bind_a_session_or_expose_provider_errors(): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        $attempt = $this->unresolvedAttempt($store['invoice']);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_endpoint' => Http::response([
            'errors' => [['detail' => 'provider-secret-fixture sk_test_endpointfixture /private/logs email=private@example.invalid']],
        ], 503)]);

        $response = $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recover', [
            'session_id' => 'cs_endpoint',
        ])->assertOk()->assertJsonPath('attempt.id', $attempt->id)->assertJsonPath('attempt.status', 'unknown')
            ->assertJsonPath('attempt.session_id', null)->assertJsonPath('checkout_url', null);

        foreach (['provider-secret-fixture', 'sk_test_endpointfixture', '/private/logs', 'private@example.invalid'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $attempt->id, 'session_id' => null, 'payment_id' => null, 'checkout_url' => null]);
        $this->assertSame('open', $store['invoice']->refresh()->status);
    }

    public function test_recovery_rejects_a_foreign_invoice_quote_without_binding_its_unowned_session(): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        $attempt = $this->unresolvedAttempt($store['invoice']);
        $foreign = $this->billing();
        $foreignAttempt = $this->unresolvedAttempt($foreign['invoice']);
        $before = $attempt->getAttributes();
        $foreignBefore = $foreignAttempt->getAttributes();
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_endpoint' => Http::response($this->pendingSession($foreignAttempt->reference))]);

        $response = $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recover', [
            'session_id' => 'cs_endpoint',
        ])->assertUnprocessable()->assertInvalid(['session_id' => 'The existing billing record was preserved.']);

        $this->assertSame($before, $attempt->refresh()->getAttributes());
        $this->assertSame($foreignBefore, $foreignAttempt->refresh()->getAttributes());
        $this->assertStringNotContainsString($foreignAttempt->reference, $response->getContent());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_recovery_requires_a_csrf_token_outside_the_test_environment_before_provider_access(): void
    {
        $this->configurePayMongo();
        $store = $this->billing();
        $attempt = $this->unresolvedAttempt($store['invoice']);
        $before = $attempt->getAttributes();
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->actingAs($store['user'])->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recover', [
            'session_id' => 'cs_endpoint',
        ])->assertStatus(419);

        Http::assertNothingSent();
        $this->assertSame($before, $attempt->refresh()->getAttributes());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_recovery_requests_are_rate_limited_without_creating_another_attempt(): void
    {
        $this->configurePayMongo();
        $this->freezeTime();
        $store = $this->billing();
        $attempt = $this->unresolvedAttempt($store['invoice']);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_endpoint' => Http::response($this->pendingSession($attempt->reference))]);
        $this->actingAs($store['user']);

        for ($request = 0; $request < 12; $request++) {
            $this->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recover', ['session_id' => 'cs_endpoint'])
                ->assertOk()->assertJsonPath('attempt.id', $attempt->id)->assertJsonPath('attempt.status', 'pending');
        }
        $this->postJson('/api/billing/invoices/'.$store['invoice']->id.'/paymongo-recover', ['session_id' => 'cs_endpoint'])
            ->assertTooManyRequests()->assertHeader('Retry-After', '60');

        Http::assertSentCount(12);
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        $this->assertSame('open', $store['invoice']->refresh()->status);
    }

    public function test_checkout_creation_is_not_repeated_after_an_unknown_response_or_a_later_recheck(): void
    {
        $this->configurePayMongo();
        $this->freezeTime();
        $store = $this->billing();
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => Http::response([], 503)]);
        $path = '/api/billing/invoices/'.$store['invoice']->id;

        $response = $this->actingAs($store['user'])->postJson($path.'/paymongo-checkout')->assertOk()
            ->assertJsonPath('attempt.status', 'unknown')->assertJsonPath('attempt.session_id', null)->assertJsonPath('checkout_url', null);
        $this->travel(2)->minutes();
        $this->postJson($path.'/paymongo-recheck')->assertOk()->assertJsonPath('attempt.id', $response->json('attempt.id'));
        $this->postJson($path.'/paymongo-checkout')->assertOk()->assertJsonPath('attempt.id', $response->json('attempt.id'))
            ->assertJsonPath('attempt.status', 'unknown')->assertJsonPath('attempt.session_id', null)->assertJsonPath('checkout_url', null);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        $this->assertSame('open', $store['invoice']->refresh()->status);
        $this->assertNull($store['subscription']->refresh()->current_period_end);
    }

    private function unresolvedAttempt(BillingInvoice $invoice): BillingPaymentAttempt
    {
        return BillingPaymentAttempt::factory()->create([
            'billing_invoice_id' => $invoice->id, 'status' => 'unknown',
            'checkout_payload' => ['data' => ['attributes' => ['payment_method_types' => ['card']]]],
        ])->refresh();
    }

    /** @return array<string, mixed> */
    private function pendingSession(string $reference, bool $live = false): array
    {
        return ['data' => ['id' => 'cs_endpoint', 'type' => 'checkout_session', 'attributes' => [
            'livemode' => $live, 'reference_number' => $reference, 'line_items' => [['amount' => 99900, 'currency' => 'PHP', 'quantity' => 1]],
            'status' => 'active', 'checkout_url' => 'https://checkout.paymongo.com/endpoint-fixture', 'payments' => [],
        ]]];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function operationalRecords(): array
    {
        $result = [];
        foreach (['products', 'stocks', 'stock_movements', 'sales', 'sale_returns', 'purchase_orders', 'purchase_receipts', 'cash_sessions', 'cash_adjustments', 'commerce_channels', 'channel_orders'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $result;
    }
}
