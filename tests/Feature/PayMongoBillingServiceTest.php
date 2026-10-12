<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\Location;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Services\PayMongoBillingService;
use App\Services\PayMongoClient;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PayMongoBillingServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const CREATE_URL = 'https://api.paymongo.com/v2/checkout_sessions';

    private const RETRIEVE_URL = 'https://api.paymongo.com/v1/checkout_sessions/';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.payment_provider' => 'paymongo', 'billing.simulations_enabled' => true,
            'paymongo.enabled' => true, 'paymongo.mode' => 'test', 'paymongo.secret_key' => 'sk_test_fixture',
            'paymongo.webhook_secret' => null, 'paymongo.payment_methods' => ['card', 'gcash', 'qrph'],
            'app.url' => 'http://127.0.0.1:8010',
        ]);
    }

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn (): string => 'testing');
        parent::tearDown();
    }

    private function configureLive(): void
    {
        config(['paymongo.mode' => 'live', 'paymongo.secret_key' => 'sk_live_fixture',
            'paymongo.webhook_secret' => 'whsec_live_fixture', 'paymongo.payment_methods' => ['qrph'],
            'app.url' => 'https://posonly.example']);
        $this->app->detectEnvironment(fn (): string => 'production');
        Http::preventStrayRequests();
    }

    /** @return array{User, BillingInvoice} */
    private function invoice(string $plan = 'grow', string $cycle = 'monthly'): array
    {
        $tenant = Tenant::factory()->create(['settings' => Tenant::defaultSettings($plan)]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id, 'plan' => $plan, 'billing_cycle' => $cycle,
            'status' => 'past_due', 'trial_ends_at' => now()->subDays(15),
        ]);
        $invoice = BillingInvoice::factory()->create([
            'tenant_id' => $tenant->id, 'subscription_id' => $subscription->id,
            'plan' => $plan, 'billing_cycle' => $cycle,
        ]);

        return [$owner, $invoice];
    }

    private function attempt(BillingInvoice $invoice, array $attributes = []): BillingPaymentAttempt
    {
        $reference = (string) Str::uuid();

        return BillingPaymentAttempt::factory()->create(array_replace([
            'billing_invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id,
            'reference' => $reference, 'status' => 'pending', 'session_id' => 'cs_existing',
            'checkout_url' => 'https://checkout.paymongo.com/cs_existing',
            'checkout_payload' => ['data' => ['attributes' => [
                'reference_number' => $reference, 'payment_method_types' => ['card', 'gcash'],
                'line_items' => [['amount' => (int) $invoice->amount_cents, 'currency' => 'PHP', 'quantity' => 1]],
            ]]],
        ], $attributes));
    }

    private function checkoutSession(BillingPaymentAttempt $attempt, bool $paid = false, string $sessionId = 'cs_existing', string $paymentId = 'pay_verified'): array
    {
        return ['data' => [
            'id' => $sessionId, 'type' => 'checkout_session', 'attributes' => [
                'reference_number' => $attempt->reference, 'livemode' => $attempt->mode === 'live', 'status' => 'active',
                'line_items' => $attempt->checkout_payload['data']['attributes']['line_items'],
                'checkout_url' => 'https://checkout.paymongo.com/'.$sessionId,
                'payments' => $paid ? [['id' => $paymentId, 'type' => 'payment', 'attributes' => [
                    'amount' => $attempt->amount_cents, 'currency' => 'PHP', 'livemode' => $attempt->mode === 'live',
                    'status' => 'paid', 'paid_at' => now()->timestamp, 'source' => ['type' => 'card'], 'refunds' => [],
                ]]] : [],
            ],
        ]];
    }

    private function sparseCreation(string $sessionId = 'cs_sparse', bool $live = false): array
    {
        return ['data' => ['id' => $sessionId, 'type' => 'checkout_session', 'attributes' => [
            'checkout_url' => 'https://checkout.paymongo.com/'.$sessionId.'#hosted-fixture',
            'livemode' => $live, 'created_at' => now()->timestamp, 'updated_at' => now()->timestamp,
        ]]];
    }

    public function test_live_qr_ph_sparse_checkout_verifies_exact_payment_and_activates_only_one_paid_period(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $this->configureLive();
        Http::fake([
            self::CREATE_URL => Http::response($this->sparseCreation('cs_live', true)),
            self::RETRIEVE_URL.'cs_live' => function (): PromiseInterface {
                $attempt = BillingPaymentAttempt::firstOrFail();
                $resource = $this->checkoutSession($attempt, true, 'cs_live', 'pay_live');
                $resource['data']['attributes']['payments'][0]['attributes']['source']['type'] = 'qrph';

                return Http::response($resource);
            },
        ]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);
        $attempt = BillingPaymentAttempt::firstOrFail();
        $periodEnd = $invoice->refresh()->period_end->toIso8601String();
        $this->travel(5)->days();
        $recheck = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('paid', $result['attempt']['status']);
        $this->assertSame('live', $result['attempt']['mode']);
        $this->assertSame('paid', $recheck['attempt']['status']);
        $this->assertSame('live', $invoice->payment_mode);
        $this->assertSame('qrph', $invoice->payment_method);
        $this->assertSame('paymongo_live', app(BillingService::class)->serializeInvoice($invoice)['payment_source']);
        $this->assertSame($periodEnd, $invoice->refresh()->period_end->toIso8601String());
        $this->assertNull($result['checkout_url']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_live_fixture:'))
            && $request->hasHeader('Idempotency-Key', $attempt->reference)
            && $request['data']['attributes']['payment_method_types'] === ['qrph']
            && $request['data']['attributes']['pass_on_fees'] === false
            && $request['data']['attributes']['success_url'] === 'https://posonly.example/app/billing?paymongo_return=success&invoice='.$invoice->id);
        $this->assertFalse(app(BillingService::class)->simulationEnabled());
    }

    public function test_live_qr_ph_pending_checkout_is_reused_without_creating_another_session(): void
    {
        [$owner, $invoice] = $this->invoice();
        $this->configureLive();
        Http::fake([self::CREATE_URL => fn (): PromiseInterface => Http::response(
            $this->checkoutSession(BillingPaymentAttempt::firstOrFail(), false, 'cs_live'),
        )]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);
        $repeat = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('pending', $result['attempt']['status']);
        $this->assertSame('https://checkout.paymongo.com/cs_live', $result['checkout_url']);
        $this->assertSame($result, $repeat);
        $this->assertSame(['qrph'], app(PayMongoClient::class)->configuration()['payment_methods']);
        $this->assertSame('open', $invoice->refresh()->status);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('billing_payment_attempts', 1);
    }

    public function test_unknown_live_creation_never_repeats_post_and_can_recover_only_its_original_verified_session(): void
    {
        [$owner, $invoice] = $this->invoice();
        $this->configureLive();
        Http::fake([self::CREATE_URL => Http::response([], 503)]);
        $first = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);
        app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);
        $attempt = BillingPaymentAttempt::firstOrFail();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
        Http::fake([self::RETRIEVE_URL.'cs_recovered_live' => function () use ($attempt): PromiseInterface {
            $resource = $this->checkoutSession($attempt, true, 'cs_recovered_live', 'pay_recovered_live');
            $resource['data']['attributes']['payments'][0]['attributes']['source']['type'] = 'qrph';

            return Http::response($resource);
        }]);

        $recovered = app(PayMongoBillingService::class)->recover($owner, $invoice->id, 'cs_recovered_live');

        $this->assertSame('unknown', $first['attempt']['status']);
        $this->assertSame('paid', $recovered['attempt']['status']);
        $this->assertSame('live', $invoice->refresh()->payment_mode);
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    #[TestWith(['startCheckout'])]
    #[TestWith(['recheck'])]
    #[TestWith(['recover'])]
    public function test_historical_test_attempts_are_preserved_and_blocked_before_live_provider_requests(string $operation): void
    {
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $before = $attempt->refresh()->getAttributes();
        $this->configureLive();

        try {
            app(PayMongoBillingService::class)->{$operation}($owner, $invoice->id, 'cs_existing');
            $this->fail('A historical test attempt must not be checked with a live key.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('another PayMongo mode', $error->errors()['payment'][0]);
        }

        $this->assertSame($before, $attempt->refresh()->getAttributes());
        $this->assertSame('open', $invoice->refresh()->status);
        Http::assertNothingSent();
    }

    #[TestWith(['session', false])]
    #[TestWith(['session', 'true'])]
    #[TestWith(['payment', false])]
    #[TestWith(['payment', 'true'])]
    public function test_live_settlement_requires_strict_true_mode_on_both_session_and_payment(string $target, mixed $mode): void
    {
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['mode' => 'live']);
        $this->configureLive();
        $resource = $this->checkoutSession($attempt, true);
        if ($target === 'session') {
            $resource['data']['attributes']['livemode'] = $mode;
        } else {
            $resource['data']['attributes']['payments'][0]['attributes']['livemode'] = $mode;
        }
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($resource)]);

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('unknown', $result['attempt']['status']);
        $this->assertSame('open', $invoice->refresh()->status);
        $this->assertNull($invoice->paid_at);
        $this->assertNull(Subscription::findOrFail($invoice->subscription_id)->current_period_end);
        Http::assertSentCount(1);
    }

    #[TestWith(['local', 'live', 'sk_live_fixture', 'https://posonly.example', 'whsec_live_fixture'])]
    #[TestWith(['testing', 'live', 'sk_live_fixture', 'https://posonly.example', 'whsec_live_fixture'])]
    #[TestWith(['production', 'test', 'sk_test_fixture', 'https://posonly.example', 'whsec_live_fixture'])]
    #[TestWith(['production', 'live', 'sk_test_fixture', 'https://posonly.example', 'whsec_live_fixture'])]
    #[TestWith(['production', 'live', 'sk_live_fixture', 'http://posonly.example', 'whsec_live_fixture'])]
    #[TestWith(['production', 'live', 'sk_live_fixture', 'https://posonly.example', ''])]
    public function test_unsafe_live_configuration_is_disabled_without_provider_requests(string $environment, string $mode, string $key, string $url, string $webhookSecret): void
    {
        [$owner, $invoice] = $this->invoice();
        config(['paymongo.mode' => $mode, 'paymongo.secret_key' => $key, 'paymongo.webhook_secret' => $webhookSecret, 'app.url' => $url]);
        $this->app->detectEnvironment(fn (): string => $environment);
        Http::preventStrayRequests();

        try {
            app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);
            $this->fail('Unsafe payment configuration must refuse checkout.');
        } catch (ValidationException|HttpException) {
            $configuration = app(PayMongoClient::class)->configuration();
            $this->assertFalse($configuration['checkout_enabled']);
            $this->assertStringNotContainsString($key, json_encode($configuration, JSON_THROW_ON_ERROR));
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('billing_payment_attempts', 0);
    }

    public function test_checkout_sends_one_server_quote_with_safe_transport_and_hides_the_stored_link(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $baseDepth = DB::transactionLevel();
        $observed = [];
        Http::preventStrayRequests();
        Http::fake([self::CREATE_URL => function (Request $request, array $options) use (&$observed) {
            $observed = ['depth' => DB::transactionLevel(), 'options' => $options];

            return Http::response($this->checkoutSession(BillingPaymentAttempt::firstOrFail()));
        }]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);
        $history = app(BillingService::class)->state($owner->tenant);

        $attempt = BillingPaymentAttempt::firstOrFail();
        $this->assertSame('pending', $result['attempt']['status']);
        $this->assertSame('https://checkout.paymongo.com/cs_existing', $result['checkout_url']);
        $this->assertSame($baseDepth, $observed['depth']);
        $this->assertFalse($observed['options']['allow_redirects']);
        $this->assertSame(3, $observed['options']['connect_timeout']);
        $this->assertSame(10, $observed['options']['timeout']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::CREATE_URL
            && $request->hasHeader('Idempotency-Key', $attempt->reference)
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_fixture:'))
            && $request->data() === ['data' => ['attributes' => [
                'line_items' => [['name' => 'POSONLY Grow subscription', 'amount' => 99900, 'currency' => 'PHP', 'quantity' => 1]],
                'payment_method_types' => ['card', 'gcash'], 'reference_number' => $attempt->reference,
                'success_url' => 'http://127.0.0.1:8010/app/billing?paymongo_return=success&invoice='.$invoice->id,
                'cancel_url' => 'http://127.0.0.1:8010/app/billing?paymongo_return=cancel&invoice='.$invoice->id,
                'send_email_receipt' => false, 'pass_on_fees' => false,
            ]]]);
        $this->assertStringNotContainsString('checkout.paymongo.com', $attempt->getRawOriginal('checkout_url'));
        $this->assertArrayNotHasKey('checkout_url', $attempt->toArray());
        $this->assertArrayNotHasKey('checkout_payload', $attempt->toArray());
        $this->assertStringNotContainsString('checkout.paymongo.com', json_encode($history));
        $this->assertStringNotContainsString('sk_test_fixture', json_encode($history));
        $this->assertFalse($history['simulation_enabled']);
        $this->assertTrue($history['payment_config']['checkout_enabled']);
        $this->assertFalse($history['payment_config']['webhook_configured']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
    }

    public function test_pending_checkout_reuses_its_attempt_without_another_post_even_without_active_scope(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['active_scope' => null]);
        Http::preventStrayRequests();

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame($attempt->id, $result['attempt']['id']);
        $this->assertSame('pending', $result['attempt']['status']);
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        Http::assertNothingSent();
    }

    public function test_unknown_creation_never_repeats_post_even_after_cooldown_or_configuration_changes(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $requests = [];
        Http::preventStrayRequests();
        Http::fake([self::CREATE_URL => function (Request $request) use (&$requests) {
            $requests[] = ['key' => $request->header('Idempotency-Key'), 'body' => $request->data()];
            if (count($requests) === 1) {
                return Http::failedConnection('private token or payer information');
            }

            return Http::response($this->checkoutSession(BillingPaymentAttempt::firstOrFail()));
        }]);
        $service = app(PayMongoBillingService::class);

        $first = $service->startCheckout($owner, $invoice->id);
        $original = BillingPaymentAttempt::firstOrFail()->getAttributes();
        $this->travel(2)->minutes();
        $checked = $service->recheck($owner, $invoice->id);
        config(['paymongo.payment_methods' => ['gcash'], 'billing.plans.grow.monthly_cents' => 199900, 'app.url' => 'https://changed.example']);
        $retry = $service->startCheckout($owner, $invoice->id);

        $this->assertSame('unknown', $first['attempt']['status']);
        $this->assertSame($first['attempt']['id'], $checked['attempt']['id']);
        $this->assertSame($first['attempt']['id'], $retry['attempt']['id']);
        $this->assertSame('unknown', $retry['attempt']['status']);
        $this->assertNull($retry['checkout_url']);
        $this->assertSame($original, BillingPaymentAttempt::firstOrFail()->getAttributes());
        $this->assertStringNotContainsString('private token', json_encode($first));
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        Http::assertSentCount(1);
    }

    public function test_stale_creating_attempt_can_be_rechecked_without_a_second_creation(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'creating', 'session_id' => null, 'last_requested_at' => now()->subSeconds(60)]);
        Http::preventStrayRequests();

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('unknown', $result['attempt']['status']);
        $this->assertSame($attempt->id, $result['attempt']['id']);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $attempt->id, 'status' => 'unknown', 'active_scope' => 'invoice:'.$invoice->id]);
        Http::assertNothingSent();
    }

    public function test_recent_creating_attempt_is_not_posted_again(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'creating', 'session_id' => null]);
        Http::preventStrayRequests();

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('creating', $result['attempt']['status']);
        $this->assertSame($attempt->id, $result['attempt']['id']);
        Http::assertNothingSent();
    }

    public function test_paid_recheck_uses_original_amount_and_provider_time_and_replays_do_not_extend_access(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-31 12:00:00', 'Asia/Manila'));
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $response = $this->checkoutSession($attempt, true);
        config(['billing.plans.grow.monthly_cents' => 199900]);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($response)]);
        $service = app(PayMongoBillingService::class);

        $paid = $service->recheck($owner, $invoice->id);
        $this->travel(1)->days();
        $service->recheck($owner, $invoice->id);
        $service->processSession('cs_existing', $attempt->reference);

        $this->assertSame('paid', $paid['attempt']['status']);
        $this->assertNull($paid['checkout_url']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'paid', 'amount_cents' => 99900,
            'payment_provider' => 'paymongo', 'payment_mode' => 'test', 'payment_method' => 'card', 'payment_key' => 'paymongo:pay_verified',
            'period_start' => '2026-01-31 12:00:00', 'period_end' => '2026-02-28 12:00:00']);
        $this->assertDatabaseHas('subscriptions', ['id' => $invoice->subscription_id, 'status' => 'active', 'current_period_end' => '2026-02-28 12:00:00']);
        Http::assertSentCount(1);
    }

    public function test_annual_test_payment_uses_a_year_without_overflow(): void
    {
        $this->travelTo(CarbonImmutable::parse('2024-02-29 10:00:00', 'Asia/Manila'));
        [$owner, $invoice] = $this->invoice(cycle: 'annual');
        $attempt = $this->attempt($invoice);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($this->checkoutSession($attempt, true))]);

        app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'paid', 'period_end' => '2025-02-28 10:00:00']);
        Http::assertSentCount(1);
    }

    public function test_webhook_reference_recovers_unknown_creation_by_verifying_the_provider_record(): void
    {
        $this->freezeSecond();
        [, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'unknown', 'session_id' => null]);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_recovered' => Http::response($this->checkoutSession($attempt, true, 'cs_recovered'))]);

        app(PayMongoBillingService::class)->processSession('cs_recovered', $attempt->reference);

        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $attempt->id, 'session_id' => 'cs_recovered', 'payment_id' => 'pay_verified', 'status' => 'paid']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'paid']);
        Http::assertSentCount(1);
    }

    public function test_webhook_does_not_claim_a_failed_verification_was_processed(): void
    {
        $this->freezeSecond();
        [, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $response = $this->checkoutSession($attempt, true);
        $response['data']['attributes']['payments'][0]['attributes']['livemode'] = true;
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($response)]);

        try {
            app(PayMongoBillingService::class)->processSession('cs_existing', $attempt->reference);
            $this->fail('Unverified payment must not complete the webhook receipt.');
        } catch (RuntimeException $error) {
            $this->assertSame('PayMongo payment could not be verified for this checkout record.', $error->getMessage());
        }

        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidEvidence')]
    public function test_invalid_provider_evidence_never_activates_the_invoice(string $path, mixed $value): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $response = $this->checkoutSession($attempt, true);
        Arr::set($response, $path, $value);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($response)]);

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('unknown', $result['attempt']['status']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'payment_provider' => null]);
        $this->assertDatabaseHas('subscriptions', ['id' => $invoice->subscription_id, 'status' => 'past_due', 'current_period_end' => null]);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $attempt->id, 'payment_id' => null, 'confirmed_at' => null]);
        Http::assertSentCount(1);
    }

    public static function invalidEvidence(): array
    {
        return [
            'different session' => ['data.id', 'cs_foreign'],
            'wrong resource' => ['data.type', 'payment_intent'],
            'wrong reference' => ['data.attributes.reference_number', 'foreign-reference'],
            'live session' => ['data.attributes.livemode', true],
            'nonboolean session mode' => ['data.attributes.livemode', 'false'],
            'wrong quote amount' => ['data.attributes.line_items.0.amount', 99901],
            'noninteger quote' => ['data.attributes.line_items.0.amount', '99900'],
            'wrong quote currency' => ['data.attributes.line_items.0.currency', 'USD'],
            'wrong quote quantity' => ['data.attributes.line_items.0.quantity', 2],
            'missing quote' => ['data.attributes.line_items', []],
            'invalid payment collection' => ['data.attributes.payments', 'paid'],
            'invalid payment identifier' => ['data.attributes.payments.0.id', '../../private'],
            'wrong paid resource' => ['data.attributes.payments.0.type', 'refund'],
            'live payment' => ['data.attributes.payments.0.attributes.livemode', true],
            'nonboolean payment mode' => ['data.attributes.payments.0.attributes.livemode', 'false'],
            'wrong paid amount' => ['data.attributes.payments.0.attributes.amount', 99901],
            'noninteger paid amount' => ['data.attributes.payments.0.attributes.amount', '99900'],
            'wrong payment currency' => ['data.attributes.payments.0.attributes.currency', 'USD'],
            'missing payment time' => ['data.attributes.payments.0.attributes.paid_at', null],
            'noninteger payment time' => ['data.attributes.payments.0.attributes.paid_at', '1760000000'],
            'old payment time' => ['data.attributes.payments.0.attributes.paid_at', 1],
            'future payment time' => ['data.attributes.payments.0.attributes.paid_at', 4000000000],
            'maximum integer payment time' => ['data.attributes.payments.0.attributes.paid_at', PHP_INT_MAX],
            'minimum integer payment time' => ['data.attributes.payments.0.attributes.paid_at', PHP_INT_MIN],
            'unsupported payment method' => ['data.attributes.payments.0.attributes.source.type', 'bank_transfer'],
        ];
    }

    public function test_succeeded_payment_intent_without_a_paid_payment_entry_is_not_payment_proof(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $response = $this->checkoutSession($attempt);
        $response['data']['attributes']['payment_intent'] = ['attributes' => ['status' => 'succeeded']];
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($response)]);

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('pending', $result['attempt']['status']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidHostedUrls')]
    public function test_an_untrusted_hosted_checkout_link_is_never_stored_or_returned(string $url): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['checkout_url' => null]);
        $response = $this->checkoutSession($attempt);
        $response['data']['attributes']['checkout_url'] = $url;
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($response)]);

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('unknown', $result['attempt']['status']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $attempt->id, 'checkout_url' => null]);
        Http::assertSentCount(1);
    }

    public static function invalidHostedUrls(): array
    {
        return [
            'HTTP' => ['http://checkout.paymongo.com/private'],
            'lookalike host' => ['https://checkout.paymongo.com.attacker.example/private'],
            'userinfo' => ['https://private@checkout.paymongo.com/session'],
            'other port' => ['https://checkout.paymongo.com:8443/session'],
            'relative URL' => ['/checkout/session'],
        ];
    }

    #[DataProvider('invalidReturnUrls')]
    public function test_unsafe_application_return_urls_disable_checkout(string $url): void
    {
        config(['app.url' => $url]);
        Http::preventStrayRequests();

        $configuration = app(PayMongoClient::class)->configuration();

        $this->assertFalse($configuration['checkout_enabled']);
        $this->assertSame('Configure a valid application URL for checkout returns.', $configuration['reason']);
        Http::assertNothingSent();
    }

    public static function invalidReturnUrls(): array
    {
        return [
            'userinfo alone' => ['https://private@app.example'],
            'query alone' => ['https://app.example?private=token'],
            'fragment alone' => ['https://app.example#private'],
            'nonlocal HTTP' => ['http://app.example'],
            'scheme-relative' => ['//app.example'],
        ];
    }

    public function test_late_unpaid_response_cannot_resurrect_an_expired_checkout(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $delayed = $this->checkoutSession($attempt);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => function () use ($attempt, $delayed) {
            $attempt->update(['status' => 'expired', 'active_scope' => null, 'checkout_url' => null]);

            return Http::response($delayed);
        }]);

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('expired', $result['attempt']['status']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $attempt->id, 'status' => 'expired', 'active_scope' => null, 'checkout_url' => null]);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
        Http::assertSentCount(1);
    }

    public function test_failed_attempt_keeps_its_terminal_state_after_an_uncertain_recheck(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'failed', 'active_scope' => null, 'checkout_url' => null]);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response(['private' => 'payer data'], 503)]);

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('failed', $result['attempt']['status']);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $attempt->id, 'status' => 'failed', 'active_scope' => null]);
        Http::assertSentCount(1);
    }

    public function test_unknown_known_session_with_null_scope_does_not_generate_a_new_checkout(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'unknown', 'active_scope' => null]);
        Http::preventStrayRequests();

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame($attempt->id, $result['attempt']['id']);
        $this->assertSame('unknown', $result['attempt']['status']);
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        Http::assertNothingSent();
    }

    public function test_expired_attempt_can_confirm_late_payment_without_losing_provenance_to_a_newer_attempt(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $old = $this->attempt($invoice, ['status' => 'expired', 'active_scope' => null, 'checkout_url' => null]);
        $new = $this->attempt($invoice, ['session_id' => 'cs_newer']);
        Http::preventStrayRequests();
        Http::fake([
            self::RETRIEVE_URL.'cs_existing' => Http::response($this->checkoutSession($old, true, paymentId: 'pay_original')),
            self::RETRIEVE_URL.'cs_newer' => Http::response($this->checkoutSession($new, true, 'cs_newer', 'pay_second')),
        ]);
        $service = app(PayMongoBillingService::class);

        $service->processSession('cs_existing', $old->reference);
        $stateBefore = app(BillingService::class)->state($owner->tenant);
        $period = $invoice->fresh()->getRawOriginal('period_end');
        $service->processSession('cs_newer', $new->reference);
        $stateAfter = app(BillingService::class)->state($owner->tenant);

        $this->assertSame($new->id, $stateBefore['invoices'][0]['latest_attempt']['id']);
        $this->assertSame($old->id, $stateBefore['invoices'][0]['confirmed_attempt']['id']);
        $this->assertSame('pay_original', $stateAfter['invoices'][0]['confirmed_attempt']['payment_id']);
        $this->assertSame('needs_review', $stateAfter['invoices'][0]['latest_attempt']['status']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'payment_key' => 'paymongo:pay_original', 'period_end' => $period]);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $new->id, 'status' => 'needs_review', 'payment_id' => 'pay_second']);
        Http::assertSentCount(2);
    }

    #[DataProvider('outstandingChanges')]
    public function test_outstanding_attempts_prevent_subscription_changes_without_a_second_invoice(string $status, string $action): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => $status]);
        Http::preventStrayRequests();
        $service = app(BillingService::class);

        try {
            match ($action) {
                'change' => $service->changePlan($owner, 'scale', 'monthly'),
                'cancel' => $service->cancel($owner),
                'checkout' => $service->checkout($owner),
            };
            $this->fail('Outstanding checkout must block subscription changes.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('payment', $error->errors());
        }

        $service->state($owner->tenant);
        $this->assertDatabaseHas('subscriptions', ['id' => $invoice->subscription_id, 'plan' => 'grow', 'cancel_at_period_end' => false]);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $attempt->id, 'status' => $status]);
        $this->assertDatabaseCount('billing_invoices', 1);
        Http::assertNothingSent();
    }

    public static function outstandingChanges(): array
    {
        return [
            'creating change' => ['creating', 'change'], 'pending change' => ['pending', 'change'],
            'unknown change' => ['unknown', 'change'], 'review change' => ['needs_review', 'change'],
            'pending cancel' => ['pending', 'cancel'], 'unknown cancel' => ['unknown', 'cancel'],
            'pending second invoice' => ['pending', 'checkout'], 'unknown second invoice' => ['unknown', 'checkout'],
        ];
    }

    public function test_an_outstanding_attempt_for_another_invoice_blocks_a_direct_checkout(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $this->attempt($invoice, ['status' => 'unknown']);
        $invoice->update(['status' => 'void']);
        $another = BillingInvoice::factory()->create(['tenant_id' => $owner->tenant_id, 'subscription_id' => $invoice->subscription_id]);
        Http::preventStrayRequests();

        try {
            app(PayMongoBillingService::class)->startCheckout($owner, $another->id);
            $this->fail('A second invoice must not create another payable session.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('payment', $error->errors());
        }

        $this->assertDatabaseCount('billing_payment_attempts', 1);
        $this->assertDatabaseHas('billing_invoices', ['id' => $another->id, 'status' => 'open']);
        Http::assertNothingSent();
    }

    public function test_a_verified_payment_after_external_invoice_void_is_retained_for_review_without_access_activation(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        Http::preventStrayRequests();
        Http::fake([self::CREATE_URL => function () use ($invoice) {
            $invoice->update(['status' => 'void']);
            Subscription::whereKey($invoice->subscription_id)->update(['cancel_at_period_end' => true]);

            return Http::response($this->checkoutSession(BillingPaymentAttempt::firstOrFail(), true));
        }]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('needs_review', $result['attempt']['status']);
        $this->assertSame('pay_verified', $result['attempt']['payment_id']);
        $this->assertNotNull($result['attempt']['confirmed_at']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'void', 'paid_at' => null]);
        $this->assertDatabaseHas('subscriptions', ['id' => $invoice->subscription_id, 'cancel_at_period_end' => true, 'current_period_end' => null]);
        Http::assertSentCount(1);
    }

    public function test_capacity_growth_during_provider_request_preserves_paid_evidence_without_activating_an_undersized_plan(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice('starter');
        Http::preventStrayRequests();
        Http::fake([self::CREATE_URL => function () use ($owner) {
            Location::factory()->count(2)->create(['tenant_id' => $owner->tenant_id]);

            return Http::response($this->checkoutSession(BillingPaymentAttempt::firstOrFail(), true));
        }]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('needs_review', $result['attempt']['status']);
        $this->assertSame('pay_verified', $result['attempt']['payment_id']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        $this->assertDatabaseHas('subscriptions', ['id' => $invoice->subscription_id, 'status' => 'past_due', 'current_period_end' => null]);
        Http::assertSentCount(1);
    }

    #[DataProvider('reviewEvidence')]
    public function test_multiple_payments_or_refunds_retain_confirmed_evidence_for_review(string $case): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $response = $this->checkoutSession($attempt, true);
        if ($case === 'multiple') {
            $second = $response['data']['attributes']['payments'][0];
            $second['id'] = 'pay_extra';
            $response['data']['attributes']['payments'][] = $second;
        } else {
            $response['data']['attributes']['payments'][0]['attributes']['refunds'] = [['id' => 'ref_test']];
        }
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($response)]);

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('needs_review', $result['attempt']['status']);
        $this->assertSame('pay_verified', $result['attempt']['payment_id']);
        $this->assertNotNull($result['attempt']['confirmed_at']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        Http::assertSentCount(1);
    }

    public static function reviewEvidence(): array
    {
        return ['multiple paid entries' => ['multiple'], 'refunded payment' => ['refunded']];
    }

    public function test_duplicate_provider_payment_cannot_activate_a_different_invoice(): void
    {
        $this->freezeSecond();
        [$firstOwner, $firstInvoice] = $this->invoice();
        $first = $this->attempt($firstInvoice, ['session_id' => 'cs_first']);
        [$secondOwner, $secondInvoice] = $this->invoice();
        $second = $this->attempt($secondInvoice, ['session_id' => 'cs_second']);
        Http::preventStrayRequests();
        Http::fake([
            self::RETRIEVE_URL.'cs_first' => Http::response($this->checkoutSession($first, true, 'cs_first')),
            self::RETRIEVE_URL.'cs_second' => Http::response($this->checkoutSession($second, true, 'cs_second')),
        ]);
        $service = app(PayMongoBillingService::class);

        $service->recheck($firstOwner, $firstInvoice->id);
        $result = $service->recheck($secondOwner, $secondInvoice->id);

        $this->assertSame('needs_review', $result['attempt']['status']);
        $this->assertNull($result['attempt']['payment_id']);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $first->id, 'status' => 'paid', 'payment_id' => 'pay_verified']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $secondInvoice->id, 'status' => 'open', 'paid_at' => null]);
        Http::assertSentCount(2);
    }

    public function test_definite_creation_failure_permits_a_new_attempt_while_retaining_the_failed_record(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $requests = 0;
        Http::preventStrayRequests();
        Http::fake([self::CREATE_URL => function () use (&$requests) {
            $requests++;
            if ($requests === 1) {
                return Http::response(['errors' => [['detail' => 'PRIVATE_KEY payer@example.com']]], 400);
            }

            return Http::response($this->checkoutSession(BillingPaymentAttempt::latest('id')->firstOrFail()));
        }]);
        $service = app(PayMongoBillingService::class);

        $failed = $service->startCheckout($owner, $invoice->id);
        $next = $service->startCheckout($owner, $invoice->id);

        $this->assertSame('failed', $failed['attempt']['status']);
        $this->assertSame('pending', $next['attempt']['status']);
        $this->assertNotSame($failed['attempt']['reference'], $next['attempt']['reference']);
        $this->assertStringNotContainsString('PRIVATE_KEY', json_encode($failed));
        $this->assertDatabaseCount('billing_payment_attempts', 2);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $failed['attempt']['id'], 'status' => 'failed', 'active_scope' => null]);
        Http::assertSentCount(2);
    }

    public function test_a_delayed_unpaid_response_cannot_overwrite_a_concurrently_verified_payment(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $response = $this->checkoutSession($attempt);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => function () use ($attempt, $response) {
            app(BillingService::class)->settleVerifiedAttempt($attempt, 'pay_concurrent', now(), 'card');

            return Http::response($response);
        }]);

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('paid', $result['attempt']['status']);
        $this->assertSame('pay_concurrent', $result['attempt']['payment_id']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'paid', 'payment_key' => 'paymongo:pay_concurrent']);
        Http::assertSentCount(1);
    }

    public function test_storage_timezone_is_explicit_when_php_timezone_differs(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-11 16:30:00', 'UTC'));
        [$owner, $invoice] = $this->invoice();
        Http::preventStrayRequests();
        Http::fake([self::CREATE_URL => fn () => Http::response($this->checkoutSession(BillingPaymentAttempt::firstOrFail(), true))]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('paid', $result['attempt']['status']);
        $this->assertSame('2026-10-11T16:30:00+00:00', $result['attempt']['created_at']);
        $this->assertSame('2026-10-11T16:30:00+00:00', $result['attempt']['confirmed_at']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'paid_at' => '2026-10-11 16:30:00']);
        Http::assertSentCount(1);
    }

    #[DataProvider('unsupportedCheckoutMethods')]
    public function test_qr_ph_or_unsupported_only_configuration_cannot_start_checkout_or_contact_the_provider(array $methods): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        config(['paymongo.payment_methods' => $methods]);
        Http::preventStrayRequests();

        try {
            app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);
            $this->fail('Unsupported test methods must not create a hosted checkout.');
        } catch (ValidationException $error) {
            $this->assertSame(['Configure an allowed payment method.'], $error->errors()['payment']);
        }

        $this->assertFalse(app(PayMongoClient::class)->configuration()['checkout_enabled']);
        $this->assertDatabaseCount('billing_payment_attempts', 0);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        Http::assertNothingSent();
    }

    public static function unsupportedCheckoutMethods(): array
    {
        return ['QR Ph only' => [['qrph']], 'unsupported only' => [['bank_transfer']], 'mixed unsupported' => [['qrph', 'unknown']]];
    }

    #[DataProvider('oldQrQuotes')]
    public function test_a_saved_qr_ph_quote_cannot_be_reopened_or_retried_and_remains_immutable(string $status, ?string $sessionId): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => $status, 'session_id' => $sessionId, 'last_requested_at' => now()->subMinutes(2)]);
        $quote = $attempt->checkout_payload;
        $quote['data']['attributes']['payment_method_types'][] = 'qrph';
        $attempt->update(['checkout_payload' => $quote]);
        $before = $attempt->refresh()->getRawOriginal();
        Http::preventStrayRequests();

        try {
            app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);
            $this->fail('An existing QR Ph quote must not be posted or reopened.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('payment', $error->errors());
        }

        $this->assertSame($before, $attempt->fresh()->getRawOriginal());
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
        Http::assertNothingSent();
    }

    public static function oldQrQuotes(): array
    {
        return ['pending hosted link' => ['pending', 'cs_existing'], 'unknown creation' => ['unknown', null], 'stale creation' => ['creating', null]];
    }

    public function test_known_qr_enabled_session_can_be_rechecked_but_its_hosted_link_is_not_returned(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $quote = $attempt->checkout_payload;
        $quote['data']['attributes']['payment_method_types'][] = 'qrph';
        $attempt->update(['checkout_payload' => $quote]);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($this->checkoutSession($attempt))]);

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('pending', $result['attempt']['status']);
        $this->assertNull($result['checkout_url']);
        $this->assertStringContainsString('Do not reopen or scan', $result['message']);
        $this->assertSame($quote, $attempt->fresh()->checkout_payload);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
        Http::assertSentCount(1);
    }

    public function test_confirmed_qr_ph_payment_is_saved_for_review_without_activating_subscription_access(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $quote = $attempt->checkout_payload;
        $quote['data']['attributes']['payment_method_types'][] = 'qrph';
        $attempt->update(['checkout_payload' => $quote]);
        $response = $this->checkoutSession($attempt, true);
        $response['data']['attributes']['payments'][0]['attributes']['source']['type'] = 'qrph';
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($response)]);
        $service = app(PayMongoBillingService::class);

        $result = $service->recheck($owner, $invoice->id);
        $service->processSession('cs_existing', $attempt->reference);

        $this->assertSame('needs_review', $result['attempt']['status']);
        $this->assertSame('pay_verified', $result['attempt']['payment_id']);
        $this->assertNotNull($result['attempt']['confirmed_at']);
        $this->assertNull($result['checkout_url']);
        $this->assertSame($quote, $attempt->fresh()->checkout_payload);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        $this->assertDatabaseHas('subscriptions', ['id' => $invoice->subscription_id, 'status' => 'past_due', 'current_period_end' => null]);
        Http::assertSentCount(1);
    }

    public function test_valid_card_payment_from_an_older_qr_enabled_quote_can_still_be_verified(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $quote = $attempt->checkout_payload;
        $quote['data']['attributes']['payment_method_types'][] = 'qrph';
        $attempt->update(['checkout_payload' => $quote]);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($this->checkoutSession($attempt, true))]);

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('paid', $result['attempt']['status']);
        $this->assertSame($quote, $attempt->fresh()->checkout_payload);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'paid', 'payment_method' => 'card', 'payment_mode' => 'test']);
        Http::assertSentCount(1);
    }

    public function test_verified_gcash_test_payment_still_settles_the_original_invoice(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        $response = $this->checkoutSession($attempt, true);
        $response['data']['attributes']['payments'][0]['attributes']['source']['type'] = 'gcash';
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($response)]);

        $result = app(PayMongoBillingService::class)->recheck($owner, $invoice->id);

        $this->assertSame('paid', $result['attempt']['status']);
        $this->assertSame('pay_verified', $result['attempt']['payment_id']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'paid', 'payment_method' => 'gcash', 'payment_provider' => 'paymongo', 'payment_mode' => 'test']);
        $this->assertDatabaseHas('subscriptions', ['id' => $invoice->subscription_id, 'status' => 'active']);
        Http::assertSentCount(1);
    }

    public function test_sparse_creation_binds_without_a_link_then_verifies_the_full_resource_outside_locks(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $baseDepth = DB::transactionLevel();
        $observed = [];
        Http::preventStrayRequests();
        Http::fake([
            self::CREATE_URL => Http::response($this->sparseCreation()),
            self::RETRIEVE_URL.'cs_sparse' => function (Request $request, array $options) use (&$observed) {
                $attempt = BillingPaymentAttempt::firstOrFail();
                $observed = ['depth' => DB::transactionLevel(), 'options' => $options,
                    'status' => $attempt->status, 'session_id' => $attempt->session_id, 'checkout_url' => $attempt->checkout_url];

                return Http::response($this->checkoutSession($attempt, sessionId: 'cs_sparse'));
            },
        ]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('unknown', $observed['status']);
        $this->assertSame('cs_sparse', $observed['session_id']);
        $this->assertNull($observed['checkout_url']);
        $this->assertSame($baseDepth, $observed['depth']);
        $this->assertFalse($observed['options']['allow_redirects']);
        $this->assertSame(3, $observed['options']['connect_timeout']);
        $this->assertSame(10, $observed['options']['timeout']);
        $this->assertSame('pending', $result['attempt']['status']);
        $this->assertSame('cs_sparse', $result['attempt']['session_id']);
        $this->assertSame('https://checkout.paymongo.com/cs_sparse', $result['checkout_url']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::RETRIEVE_URL.'cs_sparse'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_fixture:'))
            && ! $request->hasHeader('Idempotency-Key'));
    }

    public function test_sparse_creation_can_settle_only_after_the_full_get_verifies_payment(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        Http::preventStrayRequests();
        Http::fake([
            self::CREATE_URL => Http::response($this->sparseCreation()),
            self::RETRIEVE_URL.'cs_sparse' => fn () => Http::response($this->checkoutSession(BillingPaymentAttempt::firstOrFail(), true, 'cs_sparse')),
        ]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('paid', $result['attempt']['status']);
        $this->assertSame('pay_verified', $result['attempt']['payment_id']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'paid', 'payment_provider' => 'paymongo', 'payment_mode' => 'test']);
        Http::assertSentCount(2);
    }

    public function test_sparse_get_failure_preserves_the_known_session_for_recheck_without_a_second_post(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $gets = 0;
        Http::preventStrayRequests();
        Http::fake([
            self::CREATE_URL => Http::response($this->sparseCreation()),
            self::RETRIEVE_URL.'cs_sparse' => function () use (&$gets) {
                $gets++;

                return $gets === 1 ? Http::response(['private' => 'provider payload'], 503)
                    : Http::response($this->checkoutSession(BillingPaymentAttempt::firstOrFail(), true, 'cs_sparse'));
            },
        ]);
        $service = app(PayMongoBillingService::class);

        $unknown = $service->startCheckout($owner, $invoice->id);
        $repeat = $service->startCheckout($owner, $invoice->id);
        $paid = $service->recheck($owner, $invoice->id);

        $this->assertSame('unknown', $unknown['attempt']['status']);
        $this->assertSame('cs_sparse', $unknown['attempt']['session_id']);
        $this->assertNull($unknown['checkout_url']);
        $this->assertSame($unknown['attempt']['id'], $repeat['attempt']['id']);
        $this->assertSame('unknown', $repeat['attempt']['status']);
        $this->assertSame('paid', $paid['attempt']['status']);
        $this->assertStringNotContainsString('provider payload', json_encode($unknown));
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'paid']);
        Http::assertSentCount(3);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
    }

    #[DataProvider('invalidSparseCreations')]
    public function test_malformed_sparse_or_partial_create_responses_never_bind_or_expose_checkout(string $path, mixed $value): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $response = $this->sparseCreation();
        Arr::set($response, $path, $value);
        Http::preventStrayRequests();
        Http::fake([
            self::CREATE_URL => Http::response($response),
            self::RETRIEVE_URL.'cs_sparse' => fn () => Http::response($this->checkoutSession(BillingPaymentAttempt::firstOrFail(), true, 'cs_sparse')),
        ]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('unknown', $result['attempt']['status']);
        $this->assertNull($result['attempt']['session_id']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $result['attempt']['id'], 'checkout_url' => null, 'payment_id' => null]);
        Http::assertSentCount(1);
    }

    public static function invalidSparseCreations(): array
    {
        return [
            'wrong resource' => ['data.type', 'payment'],
            'invalid identifier' => ['data.id', '../../private'],
            'live resource' => ['data.attributes.livemode', true],
            'nonboolean test mode' => ['data.attributes.livemode', 'false'],
            'insecure checkout link' => ['data.attributes.checkout_url', 'http://checkout.paymongo.com/private'],
            'untrusted checkout host' => ['data.attributes.checkout_url', 'https://checkout.paymongo.com.attacker.example/private'],
            'partial wrong reference' => ['data.attributes.reference_number', 'wrong-reference'],
            'partial quote' => ['data.attributes.line_items', [['amount' => 99901, 'currency' => 'PHP', 'quantity' => 1]]],
            'unverified embedded payment' => ['data.attributes.payments', [['id' => 'pay_unverified', 'attributes' => ['status' => 'paid']]]],
        ];
    }

    #[DataProvider('mismatchedSparseRetrievals')]
    public function test_sparse_creation_requires_the_bound_id_reference_and_quote_to_match_on_retrieval(string $path, mixed $value): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        Http::preventStrayRequests();
        Http::fake([
            self::CREATE_URL => Http::response($this->sparseCreation()),
            self::RETRIEVE_URL.'cs_sparse' => function () use ($path, $value) {
                $response = $this->checkoutSession(BillingPaymentAttempt::firstOrFail(), true, 'cs_sparse');
                Arr::set($response, $path, $value);

                return Http::response($response);
            },
        ]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('unknown', $result['attempt']['status']);
        $this->assertSame('cs_sparse', $result['attempt']['session_id']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $result['attempt']['id'], 'session_id' => 'cs_sparse', 'payment_id' => null, 'checkout_url' => null]);
        Http::assertSentCount(2);
    }

    public static function mismatchedSparseRetrievals(): array
    {
        return [
            'different bound session' => ['data.id', 'cs_other'],
            'wrong original reference' => ['data.attributes.reference_number', 'wrong-reference'],
            'changed quote amount' => ['data.attributes.line_items.0.amount', 99901],
            'live full resource' => ['data.attributes.livemode', true],
        ];
    }

    #[DataProvider('unpaidTerminalStates')]
    public function test_delayed_sparse_creation_does_not_resurrect_a_terminal_attempt(string $status): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        Http::preventStrayRequests();
        Http::fake([
            self::CREATE_URL => function () use ($status) {
                BillingPaymentAttempt::firstOrFail()->update(['status' => $status, 'active_scope' => null]);

                return Http::response($this->sparseCreation());
            },
            self::RETRIEVE_URL.'cs_sparse' => fn () => Http::response($this->checkoutSession(BillingPaymentAttempt::firstOrFail(), true, 'cs_sparse')),
        ]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame($status, $result['attempt']['status']);
        $this->assertNull($result['attempt']['session_id']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
        Http::assertSentCount(1);
    }

    public static function unpaidTerminalStates(): array
    {
        return ['expired' => ['expired'], 'failed' => ['failed']];
    }

    public function test_sparse_creation_cannot_replace_a_session_bound_by_a_concurrent_response(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        Http::preventStrayRequests();
        Http::fake([
            self::CREATE_URL => function () {
                BillingPaymentAttempt::firstOrFail()->update(['status' => 'unknown', 'session_id' => 'cs_original']);

                return Http::response($this->sparseCreation());
            },
            self::RETRIEVE_URL.'cs_sparse' => fn () => Http::response($this->checkoutSession(BillingPaymentAttempt::firstOrFail(), true, 'cs_sparse')),
        ]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('unknown', $result['attempt']['status']);
        $this->assertSame('cs_original', $result['attempt']['session_id']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
        Http::assertSentCount(1);
    }

    public function test_sparse_creation_cannot_bind_a_session_owned_by_another_attempt(): void
    {
        $this->freezeSecond();
        [, $foreignInvoice] = $this->invoice();
        $foreign = $this->attempt($foreignInvoice, ['session_id' => 'cs_sparse']);
        [$owner, $invoice] = $this->invoice();
        Http::preventStrayRequests();
        Http::fake([
            self::CREATE_URL => Http::response($this->sparseCreation()),
            self::RETRIEVE_URL.'cs_sparse' => fn () => Http::response($this->checkoutSession($foreign, true, 'cs_sparse')),
        ]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('needs_review', $result['attempt']['status']);
        $this->assertNull($result['attempt']['session_id']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_payment_attempts', ['id' => $foreign->id, 'status' => 'pending', 'session_id' => 'cs_sparse']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
        Http::assertSentCount(1);
    }

    public function test_a_full_create_response_with_a_mismatched_quote_cannot_fall_back_to_sparse_binding(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        Http::preventStrayRequests();
        Http::fake([
            self::CREATE_URL => function () {
                $response = $this->checkoutSession(BillingPaymentAttempt::firstOrFail(), true, 'cs_sparse');
                $response['data']['attributes']['reference_number'] = 'different-invoice';

                return Http::response($response);
            },
            self::RETRIEVE_URL.'cs_sparse' => fn () => Http::response($this->checkoutSession(BillingPaymentAttempt::firstOrFail(), true, 'cs_sparse')),
        ]);

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame('unknown', $result['attempt']['status']);
        $this->assertNull($result['attempt']['session_id']);
        $this->assertNull($result['checkout_url']);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        Http::assertSentCount(1);
    }

    #[DataProvider('unresolvedCreationStates')]
    public function test_unresolved_creations_never_generate_another_attempt_or_post(string $status, ?string $scope): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => $status, 'session_id' => null,
            'active_scope' => $scope === null ? null : 'invoice:'.$invoice->id, 'last_requested_at' => now()->subDays(2)]);
        $original = $attempt->refresh()->getAttributes();
        Http::preventStrayRequests();

        $result = app(PayMongoBillingService::class)->startCheckout($owner, $invoice->id);

        $this->assertSame($attempt->id, $result['attempt']['id']);
        $this->assertSame($status, $result['attempt']['status']);
        $this->assertNull($result['checkout_url']);
        $this->assertSame($original, $attempt->refresh()->getAttributes());
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        Http::assertNothingSent();
    }

    public static function unresolvedCreationStates(): array
    {
        return ['creating' => ['creating', 'active'], 'unknown' => ['unknown', 'active'],
            'creating missing scope' => ['creating', null], 'unknown missing scope' => ['unknown', null]];
    }

    public function test_admin_recovers_a_verified_quote_with_get_only_and_network_io_outside_locks(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'unknown', 'session_id' => null, 'checkout_url' => null]);
        $originalQuote = $attempt->checkout_payload;
        $baseDepth = DB::transactionLevel();
        $observed = [];
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_recovered' => function (Request $request, array $options) use ($attempt, &$observed) {
            $observed = ['depth' => DB::transactionLevel(), 'options' => $options, 'stored_id' => $attempt->refresh()->session_id];

            return Http::response($this->checkoutSession($attempt, false, 'cs_recovered'));
        }]);

        $result = app(PayMongoBillingService::class)->recover($owner, $invoice->id, 'cs_recovered');

        $this->assertSame('pending', $result['attempt']['status']);
        $this->assertSame('cs_recovered', $result['attempt']['session_id']);
        $this->assertSame('https://checkout.paymongo.com/cs_recovered', $result['checkout_url']);
        $this->assertSame($baseDepth, $observed['depth']);
        $this->assertNull($observed['stored_id']);
        $this->assertFalse($observed['options']['allow_redirects']);
        $this->assertSame(10, $observed['options']['timeout']);
        $this->assertSame($originalQuote, $attempt->refresh()->checkout_payload);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === self::RETRIEVE_URL.'cs_recovered');
    }

    public function test_recovered_paid_session_activates_once_and_same_id_replay_is_idempotent(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'creating', 'session_id' => null, 'checkout_url' => null]);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_recovered' => Http::response($this->checkoutSession($attempt, true, 'cs_recovered'))]);
        $service = app(PayMongoBillingService::class);

        $paid = $service->recover($owner, $invoice->id, 'cs_recovered');
        $period = Subscription::findOrFail($invoice->subscription_id)->getAttributes();
        $this->travel(1)->day();
        $replay = $service->recover($owner, $invoice->id, 'cs_recovered');

        $this->assertSame('paid', $paid['attempt']['status']);
        $this->assertSame('paid', $replay['attempt']['status']);
        $this->assertSame($period, Subscription::findOrFail($invoice->subscription_id)->getAttributes());
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'paid', 'payment_provider' => 'paymongo', 'payment_mode' => 'test']);
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidRecoveryEvidence')]
    public function test_recovery_rejects_unverified_session_or_payment_before_binding(string $path, mixed $value): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'unknown', 'session_id' => null, 'checkout_url' => null]);
        $before = $attempt->refresh()->getAttributes();
        $response = $this->checkoutSession($attempt, true, 'cs_recovered');
        if ($path === 'unpaid_bad_url') {
            $response['data']['attributes']['payments'] = [];
            $response['data']['attributes']['checkout_url'] = $value;
        } else {
            Arr::set($response, $path, $value);
        }
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_recovered' => Http::response($response)]);

        try {
            app(PayMongoBillingService::class)->recover($owner, $invoice->id, 'cs_recovered');
            $this->fail('Invalid recovery evidence must be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('session_id', $error->errors());
        }

        $this->assertSame($before, $attempt->refresh()->getAttributes());
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        $this->assertSame('past_due', Subscription::findOrFail($invoice->subscription_id)->status);
        Http::assertSentCount(1);
    }

    public static function invalidRecoveryEvidence(): array
    {
        return [
            'wrong fetched ID' => ['data.id', 'cs_other'],
            'wrong resource type' => ['data.type', 'payment'],
            'foreign reference' => ['data.attributes.reference_number', 'unrelated-reference'],
            'live session' => ['data.attributes.livemode', true],
            'wrong quote amount' => ['data.attributes.line_items.0.amount', 100],
            'wrong quote currency' => ['data.attributes.line_items.0.currency', 'USD'],
            'noninteger quantity' => ['data.attributes.line_items.0.quantity', '1'],
            'missing payments' => ['data.attributes.payments', null],
            'live payment' => ['data.attributes.payments.0.attributes.livemode', true],
            'wrong payment amount' => ['data.attributes.payments.0.attributes.amount', 100],
            'wrong payment resource' => ['data.attributes.payments.0.type', 'refund'],
            'malformed payment ID' => ['data.attributes.payments.0.id', 'invalid'],
            'missing paid timestamp' => ['data.attributes.payments.0.attributes.paid_at', null],
            'foreign hosted URL' => ['unpaid_bad_url', 'https://foreign.invalid/checkout'],
        ];
    }

    public function test_recovery_transport_failure_keeps_the_attempt_unbound_and_blocks_creation_replay(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'creating', 'session_id' => null, 'checkout_url' => null]);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_recovered' => Http::failedConnection('private token payer body')]);
        $service = app(PayMongoBillingService::class);

        $result = $service->recover($owner, $invoice->id, 'cs_recovered');
        $this->travel(2)->minutes();
        $repeated = $service->startCheckout($owner, $invoice->id);

        $this->assertSame('unknown', $result['attempt']['status']);
        $this->assertNull($result['attempt']['session_id']);
        $this->assertNull($result['checkout_url']);
        $this->assertSame($attempt->id, $repeated['attempt']['id']);
        $this->assertStringNotContainsString('private token', json_encode($result));
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open']);
        Http::assertSentCount(1);
    }

    public function test_recovery_cannot_use_a_session_owned_by_a_different_attempt(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'unknown', 'session_id' => null, 'checkout_url' => null]);
        [, $foreignInvoice] = $this->invoice();
        $foreign = $this->attempt($foreignInvoice);
        $before = $foreign->refresh()->getAttributes();
        Http::preventStrayRequests();

        try {
            app(PayMongoBillingService::class)->recover($owner, $invoice->id, 'cs_existing');
            $this->fail('A foreign association must reject recovery.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('session_id', $error->errors());
        }

        $this->assertNull($attempt->refresh()->session_id);
        $this->assertSame($before, $foreign->refresh()->getAttributes());
        Http::assertNothingSent();
    }

    #[DataProvider('recoveryConcurrentChanges')]
    public function test_recovery_revalidates_latest_attempt_binding_and_terminal_state_after_get(string $change): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'unknown', 'session_id' => null, 'checkout_url' => null]);
        [, $foreignInvoice] = $this->invoice();
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_recovered' => function () use ($attempt, $invoice, $foreignInvoice, $change) {
            $response = $this->checkoutSession($attempt, true, 'cs_recovered');
            if ($change === 'different binding') {
                $attempt->update(['session_id' => 'cs_original']);
            } elseif ($change === 'duplicate association') {
                $this->attempt($foreignInvoice, ['session_id' => 'cs_recovered']);
            } elseif ($change === 'terminal') {
                $attempt->update(['status' => 'expired', 'active_scope' => null]);
            } else {
                $attempt->update(['status' => 'expired', 'active_scope' => null]);
                $this->attempt($invoice, ['status' => 'creating', 'session_id' => null, 'checkout_url' => null]);
            }

            return Http::response($response);
        }]);

        try {
            app(PayMongoBillingService::class)->recover($owner, $invoice->id, 'cs_recovered');
            $this->fail('A changed billing record must reject the delayed recovery.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }

        $this->assertSame($change === 'different binding' ? 'cs_original' : null, $attempt->refresh()->session_id);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        $this->assertSame('past_due', Subscription::findOrFail($invoice->subscription_id)->status);
        Http::assertSentCount(1);
    }

    public static function recoveryConcurrentChanges(): array
    {
        return ['different binding' => ['different binding'], 'duplicate association' => ['duplicate association'],
            'terminal state' => ['terminal'], 'new latest attempt' => ['latest']];
    }

    public function test_recovery_rechecks_active_admin_after_network_io_before_settlement(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'unknown', 'session_id' => null, 'checkout_url' => null]);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_recovered' => function () use ($owner, $attempt) {
            User::whereKey($owner->id)->update(['role' => 'manager']);

            return Http::response($this->checkoutSession($attempt, true, 'cs_recovered'));
        }]);

        try {
            app(PayMongoBillingService::class)->recover($owner, $invoice->id, 'cs_recovered');
            $this->fail('A demoted admin must not settle a recovery.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }

        $this->assertNull($attempt->refresh()->session_id);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        Http::assertSentCount(1);
    }

    public function test_known_session_recovery_rechecks_same_id_but_never_replaces_it(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice);
        Http::preventStrayRequests();
        Http::fake([self::RETRIEVE_URL.'cs_existing' => Http::response($this->checkoutSession($attempt))]);
        $service = app(PayMongoBillingService::class);

        $verified = $service->recover($owner, $invoice->id, 'cs_existing');
        try {
            $service->recover($owner, $invoice->id, 'cs_other');
            $this->fail('A known session cannot be replaced.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('session_id', $error->errors());
        }

        $this->assertSame('pending', $verified['attempt']['status']);
        $this->assertSame('cs_existing', $attempt->refresh()->session_id);
        Http::assertSentCount(1);
    }

    public function test_recovery_requires_an_existing_unresolved_attempt_and_preserves_a_terminal_record(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $service = app(PayMongoBillingService::class);
        Http::preventStrayRequests();

        try {
            $service->recover($owner, $invoice->id, 'cs_recovered');
            $this->fail('An invoice without an attempt cannot adopt an external session.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('payment', $error->errors());
        }
        $attempt = $this->attempt($invoice, ['status' => 'failed', 'session_id' => null, 'active_scope' => null, 'checkout_url' => null]);
        $before = $attempt->refresh()->getAttributes();
        try {
            $service->recover($owner, $invoice->id, 'cs_recovered');
            $this->fail('An unbound terminal attempt cannot be recovered.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('payment', $error->errors());
        }

        $this->assertSame($before, $attempt->refresh()->getAttributes());
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        Http::assertNothingSent();
    }

    public function test_recovery_can_confirm_an_expired_unpaid_session_before_allowing_a_fresh_attempt(): void
    {
        $this->freezeSecond();
        [$owner, $invoice] = $this->invoice();
        $attempt = $this->attempt($invoice, ['status' => 'unknown', 'session_id' => null, 'checkout_url' => null]);
        $response = $this->checkoutSession($attempt, false, 'cs_recovered');
        $response['data']['attributes']['status'] = 'expired';
        Http::preventStrayRequests();
        Http::fake([
            self::RETRIEVE_URL.'cs_recovered' => Http::response($response),
            self::CREATE_URL => fn () => Http::response($this->checkoutSession(BillingPaymentAttempt::latest('id')->firstOrFail(), false, 'cs_new')),
        ]);
        $service = app(PayMongoBillingService::class);

        $expired = $service->recover($owner, $invoice->id, 'cs_recovered');
        $new = $service->startCheckout($owner, $invoice->id);

        $this->assertSame('expired', $expired['attempt']['status']);
        $this->assertNull($expired['checkout_url']);
        $this->assertSame('cs_recovered', $attempt->refresh()->session_id);
        $this->assertNull($attempt->active_scope);
        $this->assertSame('pending', $new['attempt']['status']);
        $this->assertNotSame($attempt->id, $new['attempt']['id']);
        $this->assertDatabaseCount('billing_payment_attempts', 2);
        $this->assertDatabaseHas('billing_invoices', ['id' => $invoice->id, 'status' => 'open', 'paid_at' => null]);
        Http::assertSentCount(2);
    }
}
