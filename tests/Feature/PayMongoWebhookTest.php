<?php

namespace Tests\Feature;

use App\Jobs\ProcessPayMongoWebhook;
use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\PayMongoWebhookEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Services\PayMongoBillingService;
use App\Services\PayMongoWebhookService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class PayMongoWebhookTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.payment_provider' => 'paymongo', 'paymongo.enabled' => true, 'paymongo.mode' => 'test',
            'paymongo.secret_key' => 'sk_test_fixture', 'paymongo.webhook_secret' => 'whsec_fixture', 'app.url' => 'http://127.0.0.1:8010']);
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn (): string => 'testing');
        parent::tearDown();
    }

    private function configureLive(): void
    {
        config(['paymongo.mode' => 'live', 'paymongo.secret_key' => 'sk_live_fixture',
            'paymongo.webhook_secret' => 'whsec_fixture', 'paymongo.payment_methods' => ['qrph'], 'app.url' => 'https://posonly.example']);
        $this->app->detectEnvironment(fn (): string => 'production');
    }

    private function payload(bool $classic = false, bool $live = false): array
    {
        $event = ['type' => 'checkout_session.payment.paid', 'livemode' => $live, 'data' => [
            'id' => 'cs_webhook_fixture', 'type' => 'checkout_session', 'attributes' => [
                'livemode' => $live, 'reference_number' => 'a04db954-67d8-420d-bc91-d0779ee16859',
                'checkout_url' => 'https://checkout.paymongo.com/private-fixture-link',
                'payments' => [['id' => 'pay_untrusted_webhook', 'attributes' => ['status' => 'paid', 'amount' => 1, 'currency' => 'USD',
                    'billing' => ['name' => 'private-payer-fixture', 'email' => 'private-payer@example.test'], 'livemode' => true]]],
            ],
        ]];

        return $classic ? ['data' => ['id' => 'evt_webhook_fixture', 'type' => 'event', 'attributes' => $event]]
            : ['event_type' => 'send.webhook', 'data' => $event];
    }

    private function signature(string $body, ?int $timestamp = null): string
    {
        $timestamp ??= now()->timestamp;

        $modeField = config('paymongo.mode') === 'live' ? 'li' : 'te';

        return 't='.$timestamp.','.$modeField.'='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_fixture');
    }

    private function send(string $body, ?string $signature = null, string $path = '/webhooks/paymongo/test'): TestResponse
    {
        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $signature ?? $this->signature($body),
        ], $body);
    }

    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function test_live_signed_notification_accepts_documented_mode_omission_and_stores_only_safe_identifiers(bool $classic, bool $omitNestedMode): void
    {
        $this->configureLive();
        $payload = $this->payload($classic, true);
        if ($omitNestedMode) {
            if ($classic) {
                unset($payload['data']['attributes']['data']['attributes']['livemode']);
            } else {
                unset($payload['data']['data']['attributes']['livemode']);
            }
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->send($body, null, '/webhooks/paymongo')->assertOk()->assertJsonPath('status', 'queued');
        $this->send($body, null, '/webhooks/paymongo')->assertOk()->assertJsonPath('status', 'received');

        $receipt = PayMongoWebhookEvent::firstOrFail();
        $this->assertSame('live', $receipt->mode);
        $this->assertSame('cs_webhook_fixture', $receipt->session_id);
        $this->assertStringNotContainsString('private-payer', json_encode($receipt->getAttributes(), JSON_THROW_ON_ERROR));
        Queue::assertPushed(ProcessPayMongoWebhook::class, fn (ProcessPayMongoWebhook $job): bool => $job->eventId === $receipt->id);
        Queue::assertCount(1);
        Http::assertNothingSent();
    }

    #[TestWith(['event', false])]
    #[TestWith(['event', 'true'])]
    #[TestWith(['session', false])]
    #[TestWith(['session', null])]
    public function test_live_webhook_rejects_test_or_non_boolean_explicit_modes(string $target, mixed $mode): void
    {
        $this->configureLive();
        $payload = $this->payload(false, true);
        if ($target === 'event') {
            $payload['data']['livemode'] = $mode;
        } else {
            $payload['data']['data']['attributes']['livemode'] = $mode;
        }

        $this->send(json_encode($payload, JSON_THROW_ON_ERROR), null, '/webhooks/paymongo')->assertUnprocessable();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('paymongo_webhook_events', 0);
    }

    public function test_live_handler_requires_live_signature_and_legacy_test_path_cannot_accept_live_notifications(): void
    {
        $this->configureLive();
        $body = json_encode($this->payload(false, true), JSON_THROW_ON_ERROR);
        $testSignature = 't='.now()->timestamp.',te='.hash_hmac('sha256', now()->timestamp.'.'.$body, 'whsec_fixture');

        $this->send($body, $testSignature, '/webhooks/paymongo')->assertUnauthorized();
        $this->send($body)->assertNotFound();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('paymongo_webhook_events', 0);
    }

    public function test_stable_webhook_path_also_accepts_configured_test_signature_without_accepting_live_data(): void
    {
        $this->send(json_encode($this->payload(), JSON_THROW_ON_ERROR), null, '/webhooks/paymongo')->assertOk();
        $this->assertSame('test', PayMongoWebhookEvent::firstOrFail()->mode);
        Queue::assertCount(1);
        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_signed_test_events_queue_only_sanitized_verification_and_deduplicate_redelivery(bool $classic): void
    {
        $body = json_encode($this->payload($classic), JSON_THROW_ON_ERROR);
        $this->send($body)->assertOk()->assertJsonPath('status', 'queued')->assertHeader('Cache-Control', 'no-store, private');
        $this->send($body)->assertOk()->assertJsonPath('status', 'received');
        $receipt = PayMongoWebhookEvent::firstOrFail();
        $this->assertSame($classic ? 'evt_webhook_fixture' : 'sha256:'.hash('sha256', $body), $receipt->event_key);
        $this->assertSame('cs_webhook_fixture', $receipt->session_id);
        $this->assertSame('queued', $receipt->status);
        $stored = json_encode($receipt->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-payer', $stored);
        $this->assertStringNotContainsString('private-fixture-link', $stored);
        Queue::assertPushed(ProcessPayMongoWebhook::class, function (ProcessPayMongoWebhook $job) use ($receipt): bool {
            $this->assertStringNotContainsString('private-payer', serialize($job));
            $this->assertStringNotContainsString('sk_test_', serialize($job));

            return $job->eventId === $receipt->id && $job->afterCommit === true;
        });
        Queue::assertCount(1);
        Http::assertNothingSent();
        $this->assertDatabaseCount('paymongo_webhook_events', 1);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('billing_payment_attempts', 0);
    }

    #[TestWith(['missing'])]
    #[TestWith(['bad'])]
    #[TestWith(['live_only'])]
    #[TestWith(['duplicate_timestamp'])]
    #[TestWith(['duplicate_test_signature'])]
    #[TestWith(['expired'])]
    #[TestWith(['future'])]
    #[TestWith(['changed_body'])]
    public function test_invalid_signature_or_timestamp_never_creates_records_or_contacts_provider(string $case): void
    {
        $body = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        $signature = match ($case) {
            'missing' => '', 'bad' => 't='.now()->timestamp.',te='.str_repeat('0', 64).',li=',
            'live_only' => 't='.now()->timestamp.',li='.hash_hmac('sha256', now()->timestamp.'.'.$body, 'whsec_fixture'),
            'duplicate_timestamp' => $this->signature($body).',t='.now()->timestamp,
            'duplicate_test_signature' => $this->signature($body).',te='.str_repeat('0', 64),
            'expired' => $this->signature($body, now()->timestamp - 301), 'future' => $this->signature($body, now()->timestamp + 301),
            'changed_body' => $this->signature($body.' '),
        };
        $response = $this->send($body, $signature)->assertUnauthorized();
        $this->assertArrayNotHasKey('file', $response->json());
        $this->assertArrayNotHasKey('exception', $response->json());
        $this->assertArrayNotHasKey('trace', $response->json());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('paymongo_webhook_events', 0);
    }

    public function test_signature_is_verified_before_json_parsing_and_body_size_is_bounded(): void
    {
        $invalid = '{invalid private-payer-fixture';
        $this->send($invalid, 'wrong')->assertUnauthorized();
        $this->send($invalid)->assertUnprocessable()->assertDontSee('private-payer-fixture');
        $this->send(str_repeat(' ', 262145))->assertStatus(413);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('paymongo_webhook_events', 0);
    }

    #[TestWith([true])]
    #[TestWith([null])]
    public function test_live_or_unspecified_event_mode_is_rejected(mixed $mode): void
    {
        $payload = $this->payload();
        $payload['data']['livemode'] = $mode;
        $this->send(json_encode($payload, JSON_THROW_ON_ERROR))->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('paymongo_webhook_events', 0);
    }

    public function test_signed_unknown_test_event_is_acknowledged_without_job_or_receipt(): void
    {
        $payload = $this->payload();
        $payload['data']['type'] = 'payment.failed';
        $payload['data']['data'] = [];
        $this->send(json_encode($payload, JSON_THROW_ON_ERROR))->assertOk()->assertJsonPath('status', 'ignored');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('paymongo_webhook_events', 0);
    }

    public function test_reused_event_id_with_different_signed_content_is_a_conflict(): void
    {
        $payload = $this->payload(true);
        $this->send(json_encode($payload, JSON_THROW_ON_ERROR))->assertOk();
        $payload['data']['attributes']['data']['id'] = 'cs_different_fixture';
        $this->send(json_encode($payload, JSON_THROW_ON_ERROR))->assertConflict();
        $this->assertDatabaseCount('paymongo_webhook_events', 1);
        $this->assertSame('cs_webhook_fixture', PayMongoWebhookEvent::firstOrFail()->session_id);
        Queue::assertCount(1);
    }

    public function test_failed_verification_can_be_requeued_but_completed_receipts_are_not_replayed(): void
    {
        $body = json_encode($this->payload(true), JSON_THROW_ON_ERROR);
        $this->send($body)->assertOk();
        $receipt = PayMongoWebhookEvent::firstOrFail();
        $receipt->update(['status' => 'failed', 'failure_summary' => 'Verification failed.']);
        $this->send($body)->assertOk()->assertJsonPath('status', 'queued');
        $this->assertNull($receipt->refresh()->failure_summary);
        $receipt->update(['status' => 'processed', 'processed_at' => now()]);
        $this->send($body)->assertOk()->assertJsonPath('status', 'received');
        Queue::assertCount(2);
        $this->assertSame('processed', $receipt->refresh()->status);
    }

    public function test_post_commit_queue_failure_keeps_a_retryable_receipt_and_redelivery_can_recover(): void
    {
        $body = json_encode($this->payload(true), JSON_THROW_ON_ERROR);
        $dispatcher = Bus::getFacadeRoot();
        Bus::partialMock()->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('private-queue-error-fixture'));

        $this->send($body)->assertStatus(503)->assertDontSee('private-queue-error-fixture');
        $receipt = PayMongoWebhookEvent::firstOrFail();
        $this->assertSame('failed', $receipt->status);
        Queue::assertNothingPushed();
        Bus::swap($dispatcher);
        $this->send($body)->assertOk()->assertJsonPath('status', 'queued');

        $this->assertSame('queued', $receipt->refresh()->status);
        Queue::assertCount(1);
        $this->assertDatabaseCount('paymongo_webhook_events', 1);
    }

    public function test_a_stale_queued_receipt_can_be_requeued_without_duplicating_the_ledger(): void
    {
        $this->freezeTime();
        $body = json_encode($this->payload(true), JSON_THROW_ON_ERROR);
        $this->send($body)->assertOk()->assertJsonPath('status', 'queued');
        $this->travel(61)->seconds();
        $this->send($body)->assertOk()->assertJsonPath('status', 'queued');
        $this->send($body)->assertOk()->assertJsonPath('status', 'received');

        Queue::assertCount(2);
        $this->assertDatabaseCount('paymongo_webhook_events', 1);
        Http::assertNothingSent();
    }

    #[TestWith(['enabled', false])]
    #[TestWith(['mode', 'live'])]
    #[TestWith(['secret_key', 'sk_live_fixture'])]
    public function test_webhook_is_unavailable_when_test_integration_is_disabled_or_live(string $setting, mixed $value): void
    {
        config(['paymongo.'.$setting => $value]);
        $this->send(json_encode($this->payload(), JSON_THROW_ON_ERROR))->assertNotFound();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('paymongo_webhook_events', 0);
    }

    public function test_production_webhooks_and_missing_verification_secret_are_disabled(): void
    {
        config(['paymongo.webhook_secret' => '']);
        $this->send(json_encode($this->payload(), JSON_THROW_ON_ERROR))->assertStatus(503);
        config(['paymongo.webhook_secret' => 'whsec_fixture']);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->send(json_encode($this->payload(), JSON_THROW_ON_ERROR))->assertNotFound();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('paymongo_webhook_events', 0);
    }

    public function test_only_the_signed_webhook_route_is_exempt_from_request_forgery_protection(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->send(json_encode($this->payload(), JSON_THROW_ON_ERROR))->assertOk()->assertJsonPath('status', 'queued');
        $this->postJson('/api/billing/checkout')->assertStatus(419);

        Queue::assertCount(1);
        Http::assertNothingSent();
    }

    private function checkout(bool $live = false): BillingPaymentAttempt
    {
        $user = User::factory()->create(['tenant_id' => Tenant::factory(), 'role' => 'admin']);
        app(BillingService::class)->checkout($user);
        $invoice = BillingInvoice::where('tenant_id', $user->tenant_id)->firstOrFail();
        if ($live) {
            $this->configureLive();
        }
        Http::fake(['https://api.paymongo.com/v2/checkout_sessions' => function ($request) use ($live) {
            $quote = $request->data()['data']['attributes'];

            return Http::response(['data' => ['id' => 'cs_webhook_fixture', 'type' => 'checkout_session', 'attributes' => [
                'livemode' => $live, 'reference_number' => $quote['reference_number'], 'line_items' => $quote['line_items'],
                'status' => 'active', 'checkout_url' => 'https://checkout.paymongo.com/private-fixture-link', 'payments' => [],
            ]]], 201);
        }]);
        app(PayMongoBillingService::class)->startCheckout($user, $invoice->id);
        Http::assertSentCount(1);

        return BillingPaymentAttempt::where('billing_invoice_id', $invoice->id)->firstOrFail();
    }

    private function queueAttempt(BillingPaymentAttempt $attempt): PayMongoWebhookEvent
    {
        $live = $attempt->mode === 'live';
        $payload = $this->payload(false, $live);
        $payload['data']['data']['attributes']['reference_number'] = $attempt->reference;
        $this->send(json_encode($payload, JSON_THROW_ON_ERROR), null, $live ? '/webhooks/paymongo' : '/webhooks/paymongo/test')->assertOk()->assertJsonPath('status', 'queued');

        return PayMongoWebhookEvent::firstOrFail();
    }

    public function test_queued_job_retrieves_provider_payment_and_replay_never_extends_the_period(): void
    {
        $this->freezeTime();
        $attempt = $this->checkout();
        $receipt = $this->queueAttempt($attempt);
        $quote = $attempt->checkout_payload['data']['attributes'];
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_webhook_fixture' => Http::response([
            'data' => ['id' => $attempt->session_id, 'type' => 'checkout_session', 'attributes' => [
                'livemode' => false, 'reference_number' => $attempt->reference, 'line_items' => $quote['line_items'],
                'status' => 'active', 'payments' => [['id' => 'pay_verified_fixture', 'type' => 'payment', 'attributes' => [
                    'status' => 'paid', 'livemode' => false, 'amount' => $attempt->amount_cents, 'currency' => 'PHP',
                    'paid_at' => now()->timestamp, 'source' => ['type' => 'card'], 'refunds' => [],
                    'billing' => ['name' => 'private-payer-fixture', 'email' => 'private-payer@example.test'],
                ]]],
            ]],
        ])]);
        $job = new ProcessPayMongoWebhook($receipt->id);

        $job->handle(app(PayMongoBillingService::class));
        $invoice = $attempt->invoice->refresh();
        $periodEnd = $invoice->period_end->toIso8601String();
        $this->travel(5)->days();
        $job->handle(app(PayMongoBillingService::class));

        $this->assertSame('paid', $invoice->status);
        $this->assertSame('paymongo', $invoice->payment_provider);
        $this->assertSame('test', $invoice->payment_mode);
        $this->assertSame('pay_verified_fixture', $attempt->refresh()->payment_id);
        $this->assertSame($periodEnd, $invoice->refresh()->period_end->toIso8601String());
        $this->assertSame('processed', $receipt->refresh()->status);
        $this->assertNotNull($receipt->processed_at);
        $this->assertStringNotContainsString('private-payer', json_encode($attempt->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('billing_invoices', 1);
        $this->assertDatabaseCount('billing_payment_attempts', 1);
        Http::assertSentCount(1);
    }

    public function test_worker_failure_keeps_invoice_open_and_stores_only_a_safe_retry_notice(): void
    {
        $attempt = $this->checkout();
        $receipt = $this->queueAttempt($attempt);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_webhook_fixture' => Http::response([
            'errors' => [['detail' => 'private-provider-body sk_test_private-fixture']],
        ], 500)]);
        $job = new ProcessPayMongoWebhook($receipt->id);

        try {
            $job->handle(app(PayMongoBillingService::class));
            $this->fail('Unverified payment must fail so the worker can retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('PayMongo payment verification is temporarily unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $job->failed($exception);
        }

        $this->assertSame('open', $attempt->invoice->refresh()->status);
        $this->assertNull($attempt->invoice->paid_at);
        $this->assertSame('pending', $attempt->refresh()->status);
        $this->assertSame('failed', $receipt->refresh()->status);
        $this->assertStringNotContainsString('private-provider-body', $receipt->failure_summary);
        $this->assertStringNotContainsString('sk_test_', serialize($job));
        $this->assertDatabaseCount('billing_payment_attempts', 1);
    }

    public function test_billing_runtime_failures_return_generic_json_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        $this->mock(PayMongoWebhookService::class)->shouldReceive('receive')->once()
            ->andThrow(new RuntimeException('private-provider-secret /private/path provider-response-fixture'));
        $response = $this->send(json_encode($this->payload(), JSON_THROW_ON_ERROR))->assertStatus(503)
            ->assertJsonPath('message', 'Billing is temporarily unavailable. Refresh billing or recheck the invoice payment.');
        $response->assertDontSee('private-provider-secret')->assertDontSee('provider-response-fixture')->assertDontSee('/private/path');
        $this->assertArrayNotHasKey('exception', $response->json());
    }

    public function test_live_queue_job_ignores_notification_payment_claims_and_settles_only_retrieved_live_qr_ph_payment_once(): void
    {
        $this->freezeTime();
        $attempt = $this->checkout(true);
        $receipt = $this->queueAttempt($attempt);
        Http::fake(['https://api.paymongo.com/v1/checkout_sessions/cs_webhook_fixture' => Http::response([
            'data' => ['id' => $attempt->session_id, 'type' => 'checkout_session', 'attributes' => [
                'livemode' => true, 'reference_number' => $attempt->reference,
                'line_items' => $attempt->checkout_payload['data']['attributes']['line_items'],
                'status' => 'active', 'payments' => [['id' => 'pay_verified_live', 'type' => 'payment', 'attributes' => [
                    'status' => 'paid', 'livemode' => true, 'amount' => $attempt->amount_cents, 'currency' => 'PHP',
                    'paid_at' => now()->timestamp, 'source' => ['type' => 'qrph'], 'refunds' => [],
                ]]],
            ]],
        ])]);
        $job = new ProcessPayMongoWebhook($receipt->id);

        $job->handle(app(PayMongoBillingService::class));
        $periodEnd = $attempt->invoice->refresh()->period_end->toIso8601String();
        $this->travel(3)->days();
        $job->handle(app(PayMongoBillingService::class));

        $this->assertSame('paid', $attempt->refresh()->status);
        $this->assertSame('pay_verified_live', $attempt->payment_id);
        $this->assertSame('live', $attempt->invoice->payment_mode);
        $this->assertSame('qrph', $attempt->invoice->payment_method);
        $this->assertSame($periodEnd, $attempt->invoice->refresh()->period_end->toIso8601String());
        $this->assertSame('processed', $receipt->refresh()->status);
        $this->assertSame('live', $receipt->mode);
        Http::assertSentCount(1);
    }

    public function test_queued_test_receipt_cannot_use_live_configuration_or_contact_provider_after_mode_change(): void
    {
        $attempt = $this->checkout();
        $receipt = $this->queueAttempt($attempt);
        $before = $attempt->refresh()->getAttributes();
        $this->configureLive();
        Http::fake();
        $job = new ProcessPayMongoWebhook($receipt->id);

        try {
            $job->handle(app(PayMongoBillingService::class));
            $this->fail('A queued test receipt must not verify through a live connection.');
        } catch (RuntimeException $error) {
            $this->assertSame('PayMongo payment verification is temporarily unavailable.', $error->getMessage());
            $job->failed($error);
        }

        $this->assertSame($before, $attempt->refresh()->getAttributes());
        $this->assertSame('open', $attempt->invoice->refresh()->status);
        $this->assertSame('failed', $receipt->refresh()->status);
        Http::assertNothingSent();
    }
}
