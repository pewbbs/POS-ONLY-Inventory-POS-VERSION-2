<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use App\Models\CommerceChannel;
use App\Models\PayMongoWebhookEvent;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function productionConfiguration(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://pos.shop-owned-by-test.dev',
            'app.cipher' => 'AES-256-CBC',
            'stockroom.demo_enabled' => false,
            'billing.simulations_enabled' => false,
            'paymongo.enabled' => false,
            'paymongo.mode' => 'test',
            'paymongo.secret_key' => '',
            'paymongo.webhook_secret' => '',
            'commerce.simulations_enabled' => false,
            'stockroom.worker_timeout' => 60,
            'session.secure' => true,
            'session.encrypt' => true,
            'session.same_site' => 'lax',
            'session.driver' => 'database',
            'cache.default' => 'database',
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 90,
            'database.default' => 'mysql',
            'database.connections.mysql.username' => 'shop_app',
            'database.connections.mysql.password' => 'fixture-secret-never-display',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.shop-owned-by-test.dev',
            'mail.from.address' => 'support@shop-owned-by-test.dev',
            'trustedproxy.proxies' => null,
        ]);
    }

    public function test_configuration_only_passes_without_connecting_to_unavailable_database(): void
    {
        $this->productionConfiguration();
        config(['database.connections.mysql.host' => 'unreachable.invalid']);

        $this->artisan('stockroom:preflight', ['--configuration-only' => true])
            ->expectsOutput('Configuration checks passed. Database checks were skipped.')
            ->doesntExpectOutputToContain('fixture-secret-never-display')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_unsafe_production_profile_fails_with_actionable_messages_and_no_secrets(): void
    {
        $this->productionConfiguration();
        config([
            'app.debug' => true,
            'app.key' => 'invalid-key-never-display',
            'app.url' => 'http://localhost',
            'stockroom.demo_enabled' => true,
            'session.secure' => false,
            'queue.default' => 'sync',
            'mail.default' => 'log',
        ]);

        $this->artisan('stockroom:preflight', ['--configuration-only' => true])
            ->expectsOutputToContain('APP_DEBUG must be false')
            ->expectsOutputToContain('APP_KEY must contain a valid key')
            ->expectsOutputToContain('APP_URL must be a real HTTPS')
            ->expectsOutputToContain('DEMO_ENABLED must be false')
            ->expectsOutputToContain('Session cookies must require HTTPS')
            ->expectsOutputToContain('Queue must use a durable')
            ->expectsOutputToContain('Configure a delivery mailer')
            ->doesntExpectOutputToContain('invalid-key-never-display')
            ->doesntExpectOutputToContain('fixture-secret-never-display')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_worker_timeout_not_below_retry_after_fails(): void
    {
        $this->productionConfiguration();
        config(['queue.connections.database.retry_after' => 60]);

        $this->artisan('stockroom:preflight', ['--configuration-only' => true])
            ->expectsOutputToContain('Queue retry_after must be greater than the worker timeout')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_production_rejects_enabled_billing_simulations(): void
    {
        $this->productionConfiguration();
        config(['billing.simulations_enabled' => true]);

        $this->artisan('stockroom:preflight', ['--configuration-only' => true])
            ->expectsOutputToContain('BILLING_SIMULATIONS_ENABLED must be false')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_production_rejects_enabled_commerce_simulations(): void
    {
        $this->productionConfiguration();
        config(['commerce.simulations_enabled' => true]);

        $this->artisan('stockroom:preflight', ['--configuration-only' => true])
            ->expectsOutputToContain('COMMERCE_SIMULATIONS_ENABLED must be false')
            ->assertExitCode(Command::FAILURE);
    }

    #[TestWith(['test'])]
    #[TestWith(['live'])]
    public function test_production_rejects_enabled_paymongo_even_when_a_live_mode_is_requested(string $mode): void
    {
        $this->productionConfiguration();
        config(['paymongo.enabled' => true, 'paymongo.mode' => $mode]);

        $this->artisan('stockroom:preflight', ['--configuration-only' => true])
            ->expectsOutputToContain('PAYMONGO_ENABLED must be false. PayMongo billing is test-only')
            ->assertExitCode(Command::FAILURE);
    }

    #[TestWith(['secret_key', 'sk_test_private-fixture-never-display'])]
    #[TestWith(['webhook_secret', 'whsec_private-fixture-never-display'])]
    public function test_disabled_paymongo_still_rejects_test_credentials_without_printing_them(string $field, string $secret): void
    {
        $this->productionConfiguration();
        config(['paymongo.'.$field => $secret]);

        $this->artisan('stockroom:preflight', ['--configuration-only' => true])
            ->expectsOutputToContain('Remove PayMongo test credentials from production configuration')
            ->doesntExpectOutputToContain($secret)
            ->assertExitCode(Command::FAILURE);
    }

    public function test_production_rejects_test_payment_attempts_without_changing_them(): void
    {
        $attempt = BillingPaymentAttempt::factory()->create(['status' => 'unknown']);
        $before = $attempt->refresh()->getAttributes();
        $this->productionConfiguration();
        config(['database.default' => 'sqlite']);

        $this->artisan('stockroom:preflight', ['--allow-sqlite' => true])
            ->expectsOutputToContain('The production database contains PayMongo test payment attempts')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame($before, $attempt->refresh()->getAttributes());
        $this->assertDatabaseCount('billing_payment_attempts', 1);
    }

    public function test_production_rejects_test_invoice_records_without_changing_them(): void
    {
        $invoice = BillingInvoice::factory()->create(['status' => 'paid', 'payment_provider' => 'paymongo', 'payment_mode' => 'test']);
        $before = $invoice->refresh()->getAttributes();
        $this->productionConfiguration();
        config(['database.default' => 'sqlite']);

        $this->artisan('stockroom:preflight', ['--allow-sqlite' => true])
            ->expectsOutputToContain('The production database contains PayMongo test invoice records')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame($before, $invoice->refresh()->getAttributes());
    }

    public function test_production_rejects_test_webhook_receipts_without_deleting_them(): void
    {
        $receipt = PayMongoWebhookEvent::factory()->create();
        $before = $receipt->refresh()->getAttributes();
        $this->productionConfiguration();
        config(['database.default' => 'sqlite']);

        $this->artisan('stockroom:preflight', ['--allow-sqlite' => true])
            ->expectsOutputToContain('The production database contains PayMongo test webhook receipts')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame($before, $receipt->refresh()->getAttributes());
        $this->assertDatabaseCount('paymongo_webhook_events', 1);
    }

    #[TestWith(['monitoring_events'])]
    #[TestWith(['system_heartbeats'])]
    #[TestWith(['billing_payment_attempts'])]
    #[TestWith(['paymongo_webhook_events'])]
    #[TestWith(['platform_admins'])]
    #[TestWith(['developer_sessions'])]
    #[TestWith(['platform_audit_logs'])]
    #[TestWith(['site_contents'])]
    #[TestWith(['social_identities'])]
    public function test_full_preflight_rejects_missing_release_schema(string $table): void
    {
        Schema::drop($table);
        $this->productionConfiguration();
        config(['database.default' => 'sqlite']);

        $this->artisan('stockroom:preflight', ['--allow-sqlite' => true])
            ->expectsOutputToContain('Required database table is missing: '.$table)
            ->doesntExpectOutputToContain('The database could not be checked')
            ->assertExitCode(Command::FAILURE);
    }

    #[TestWith(['billing_invoices', 'payment_mode'])]
    #[TestWith(['tenants', 'operations_paused'])]
    public function test_missing_release_column_is_reported_without_attempting_an_invalid_data_query(string $table, string $column): void
    {
        Schema::table($table, function (Blueprint $table) use ($column): void {
            $table->dropColumn($column);
        });
        $this->productionConfiguration();
        config(['database.default' => 'sqlite']);

        $this->artisan('stockroom:preflight', ['--allow-sqlite' => true])
            ->expectsOutputToContain('Required database column is missing: '.$table.'.'.$column)
            ->doesntExpectOutputToContain('The database could not be checked')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_production_rejects_simulated_channels_without_removing_them(): void
    {
        $channel = CommerceChannel::factory()->create();
        $this->productionConfiguration();
        config(['database.default' => 'sqlite']);

        $this->artisan('stockroom:preflight', ['--allow-sqlite' => true])
            ->expectsOutputToContain('The production database contains simulated commerce channels')
            ->assertExitCode(Command::FAILURE);
        $this->assertModelExists($channel);
    }

    public function test_production_rejects_simulated_paid_records_without_removing_them(): void
    {
        $invoice = BillingInvoice::factory()->create(['status' => 'paid', 'payment_method' => 'simulation']);
        $this->productionConfiguration();
        config(['database.default' => 'sqlite']);

        $this->artisan('stockroom:preflight', ['--allow-sqlite' => true])
            ->expectsOutputToContain('The production database contains simulated billing payments')
            ->assertExitCode(Command::FAILURE);
        $this->assertModelExists($invoice);
    }

    public function test_application_url_query_is_rejected_before_it_breaks_reset_links(): void
    {
        $this->productionConfiguration();
        config(['app.url' => 'https://pos.shop-owned-by-test.dev/?campaign=welcome']);

        $this->artisan('stockroom:preflight', ['--configuration-only' => true])
            ->expectsOutputToContain('APP_URL must be a real HTTPS')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_placeholder_database_password_and_superuser_account_fail(): void
    {
        $this->productionConfiguration();
        config(['database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => 'replace-with-a-secret']);

        $this->artisan('stockroom:preflight', ['--configuration-only' => true])
            ->expectsOutputToContain('Set a unique application database password')
            ->expectsOutputToContain('rather than a database superuser')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_unsupported_redis_client_fails_before_runtime_use(): void
    {
        $this->productionConfiguration();
        config(['cache.default' => 'redis', 'database.redis.client' => 'unavailable-client']);

        $this->artisan('stockroom:preflight', ['--configuration-only' => true])
            ->expectsOutputToContain('Redis requires a configured and installed')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_uppercase_placeholder_mail_host_is_rejected(): void
    {
        $this->productionConfiguration();
        config(['mail.mailers.smtp.host' => 'SMTP.EXAMPLE.COM']);

        $this->artisan('stockroom:preflight', ['--configuration-only' => true])
            ->expectsOutputToContain('Configure a delivery mailer')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_full_preflight_passes_for_explicit_single_instance_sqlite(): void
    {
        Tenant::factory()->create(['is_demo' => false]);
        $this->productionConfiguration();
        config(['database.default' => 'sqlite']);

        $this->artisan('stockroom:preflight', ['--allow-sqlite' => true])
            ->expectsOutputToContain('SQLite is for a single-instance evaluation')
            ->expectsOutput('Configuration and database checks passed.')
            ->assertExitCode(Command::SUCCESS);
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_full_preflight_rejects_demo_data_without_removing_it(): void
    {
        $tenant = Tenant::factory()->create(['is_demo' => true]);
        $this->productionConfiguration();
        config(['database.default' => 'sqlite']);

        $this->artisan('stockroom:preflight', ['--allow-sqlite' => true])
            ->expectsOutputToContain('The production database contains a demo workspace')
            ->assertExitCode(Command::FAILURE);
        $this->assertModelExists($tenant);
    }

    public function test_database_check_failure_is_sanitized(): void
    {
        $this->productionConfiguration();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => '/path-that-does-not-exist/secret-db-name.sqlite']);
        DB::purge('sqlite');

        $this->artisan('stockroom:preflight', ['--allow-sqlite' => true])
            ->expectsOutputToContain('The database could not be checked')
            ->doesntExpectOutputToContain('secret-db-name')
            ->assertExitCode(Command::FAILURE);
    }
}
