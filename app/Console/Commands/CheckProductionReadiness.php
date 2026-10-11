<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Predis\Client;
use Throwable;

#[Signature('stockroom:preflight {--configuration-only : Skip database connectivity and schema checks} {--allow-sqlite : Allow SQLite for a single-instance evaluation}')]
#[Description('Check production configuration without changing data or displaying secrets')]
class CheckProductionReadiness extends Command
{
    public function handle(): int
    {
        $failures = [];
        $warnings = [];
        $this->info('POSONLY production preflight');

        $this->check(app()->isProduction(), 'APP_ENV must be production.', $failures);
        $this->check(! config('app.debug'), 'APP_DEBUG must be false.', $failures);
        $this->check($this->hasValidKey(), 'APP_KEY must contain a valid key for the configured cipher.', $failures);
        $this->check($this->hasPublicHttpsUrl(), 'APP_URL must be a real HTTPS application URL.', $failures);
        $this->check(! config('stockroom.demo_enabled'), 'DEMO_ENABLED must be false.', $failures);
        $this->check(! config('billing.simulations_enabled'), 'BILLING_SIMULATIONS_ENABLED must be false.', $failures);
        $this->check(! config('commerce.simulations_enabled'), 'COMMERCE_SIMULATIONS_ENABLED must be false.', $failures);
        $this->check(! config('paymongo.enabled', false), 'PAYMONGO_ENABLED must be false. PayMongo billing is test-only in this release.', $failures);
        $this->check(! $this->hasPayMongoTestCredentials(), 'Remove PayMongo test credentials from production configuration.', $failures);
        $this->check((bool) config('session.secure'), 'Session cookies must require HTTPS.', $failures);
        $this->check(in_array(config('session.same_site'), ['lax', 'strict'], true), 'Session cookies must use SameSite lax or strict.', $failures);
        $this->check(in_array(config('session.driver'), ['database', 'redis'], true), 'Sessions must use a shared database or Redis store.', $failures);
        $this->check(in_array(config('cache.default'), ['database', 'redis'], true), 'Cache must use a shared database or Redis store for locks.', $failures);

        $queue = config('queue.default');
        if (in_array('redis', [config('session.driver'), config('cache.default'), $queue], true)) {
            $client = config('database.redis.client');
            $this->check(($client === 'phpredis' && extension_loaded('redis')) || ($client === 'predis' && class_exists(Client::class)), 'Redis requires a configured and installed PhpRedis or Predis client.', $failures);
        }
        $this->check(in_array($queue, ['database', 'redis'], true), 'Queue must use a durable database or Redis connection.', $failures);
        $this->check(config('stockroom.worker_timeout') > 0 && config('queue.connections.'.$queue.'.retry_after', 0) > config('stockroom.worker_timeout'), 'Queue retry_after must be greater than the worker timeout.', $failures);

        $database = config('database.default');
        $this->check(in_array($database, ['mysql', 'mariadb', 'pgsql'], true) || ($database === 'sqlite' && $this->option('allow-sqlite')), 'Use MySQL/PostgreSQL, or explicitly allow single-instance SQLite.', $failures);
        if (in_array($database, ['mysql', 'mariadb', 'pgsql'], true)) {
            $password = (string) config('database.connections.'.$database.'.password');
            $username = (string) config('database.connections.'.$database.'.username');
            $this->check($password !== '' && ! str_starts_with($password, 'replace-') && ! in_array(strtolower($password), ['changeme', 'change-me', 'password', 'secret'], true), 'Set a unique application database password.', $failures);
            $this->check(! in_array(strtolower($username), ['', 'root', 'postgres'], true), 'Use an application database account rather than a database superuser.', $failures);
        }
        if ($database === 'sqlite' && $this->option('allow-sqlite')) {
            $warnings[] = 'SQLite is for a single-instance evaluation; use persistent local storage and verify concurrent checkout behavior.';
        }

        $this->check($this->hasDeliveryMailer(), 'Configure a delivery mailer and a real sender for account recovery.', $failures);
        if (in_array(config('trustedproxy.proxies'), ['*', '**'], true)) {
            $warnings[] = 'All proxy addresses are trusted. Restrict direct access to the web container and review the proxy boundary.';
        }
        if (! config('session.encrypt')) {
            $warnings[] = 'Consider SESSION_ENCRYPT=true to protect stored session contents.';
        }

        if (! $this->option('configuration-only')) {
            $this->checkDatabase($failures, $warnings);
        }
        foreach ($failures as $failure) {
            $this->error('FAIL: '.$failure);
        }
        foreach ($warnings as $warning) {
            $this->warn('WARN: '.$warning);
        }
        if ($failures !== []) {
            $this->error('Preflight failed. Correct the listed settings before opening this service.');

            return self::FAILURE;
        }
        $this->info($this->option('configuration-only') ? 'Configuration checks passed. Database checks were skipped.' : 'Configuration and database checks passed.');
        $this->line('This check does not verify email delivery, TLS termination, backups, queue processing, or external integrations.');

        return self::SUCCESS;
    }

    /** @param array<int, string> $failures */
    private function check(bool $passes, string $message, array &$failures): void
    {
        if (! $passes) {
            $failures[] = $message;
        }
    }

    private function hasValidKey(): bool
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        return is_string($key) && Encrypter::supported($key, (string) config('app.cipher'));
    }

    private function hasPayMongoTestCredentials(): bool
    {
        $secret = strtolower(trim((string) config('paymongo.secret_key', '')));
        $testWebhookSecret = config('paymongo.mode', 'test') === 'test' && trim((string) config('paymongo.webhook_secret', '')) !== '';

        return str_starts_with($secret, 'sk_test_') || $testWebhookSecret;
    }

    private function hasPublicHttpsUrl(): bool
    {
        $url = (string) config('app.url');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null
            && parse_url($url, PHP_URL_QUERY) === null
            && parse_url($url, PHP_URL_FRAGMENT) === null
            && ! $this->isPlaceholderHost($host);
    }

    private function hasDeliveryMailer(): bool
    {
        $mailer = config('mail.default');
        $transport = config('mail.mailers.'.$mailer.'.transport');
        $sender = (string) config('mail.from.address');
        $senderDomain = substr(strrchr($sender, '@') ?: '', 1);
        if (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)
            || filter_var($sender, FILTER_VALIDATE_EMAIL) === false
            || $this->isPlaceholderHost($senderDomain)) {
            return false;
        }

        return $transport !== 'smtp' || ! $this->isPlaceholderHost((string) config('mail.mailers.'.$mailer.'.host'));
    }

    private function isPlaceholderHost(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', 'example.com', 'example.org', 'example.net'], true)
            || str_ends_with($host, '.example') || str_ends_with($host, '.test') || str_ends_with($host, '.invalid')
            || str_ends_with($host, '.example.com') || str_ends_with($host, '.example.org') || str_ends_with($host, '.example.net');
    }

    /**
     * @param  array<int, string>  $failures
     * @param  array<int, string>  $warnings
     */
    private function checkDatabase(array &$failures, array &$warnings): void
    {
        try {
            DB::connection()->getPdo();
            $required = ['users', 'tenants', 'locations', 'products', 'stocks', 'sales', 'stock_movements', 'purchase_orders', 'customers', 'suppliers', 'low_stock_alerts', 'analytics_events', 'activity_logs', 'password_reset_tokens', 'subscriptions', 'billing_invoices', 'billing_payment_attempts', 'paymongo_webhook_events', 'sale_returns', 'reorder_rules', 'purchase_receipts', 'cash_sessions', 'cash_adjustments', 'commerce_channels', 'channel_listings', 'channel_orders', 'channel_syncs', 'product_families', 'product_variant_batches', 'monitoring_events', 'system_heartbeats', 'platform_admins', 'developer_sessions', 'platform_audit_logs', 'site_contents', 'social_identities'];
            if (config('session.driver') === 'database') {
                $required[] = 'sessions';
            }
            if (config('cache.default') === 'database') {
                $required = array_merge($required, ['cache', 'cache_locks']);
            }
            if (config('queue.default') === 'database') {
                $required = array_merge($required, ['jobs', 'failed_jobs']);
            }
            foreach ($required as $table) {
                $this->check(Schema::hasTable($table), 'Required database table is missing: '.$table.'. Run the release migrations.', $failures);
            }
            if (Schema::hasTable('users')) {
                $this->check(Schema::hasColumn('users', 'is_active'), 'Staff access migration is missing. Run the release migrations.', $failures);
            }
            foreach (['tenants' => ['operations_paused'], 'sales' => ['returned_cents', 'price_tier', 'cash_session_id', 'source', 'channel_id'], 'stocks' => ['reserved_quantity'], 'products' => ['wholesale_price_cents', 'product_family_id', 'variant_options', 'variant_key'], 'purchase_orders' => ['source', 'reorder_rule_id', 'approved_at', 'cancelled_at'], 'billing_invoices' => ['payment_provider', 'payment_mode'], 'billing_payment_attempts' => ['tenant_id', 'billing_invoice_id', 'mode', 'amount_cents', 'currency', 'status']] as $table => $columns) {
                if (Schema::hasTable($table)) {
                    foreach ($columns as $column) {
                        $this->check(Schema::hasColumn($table, $column), 'Required database column is missing: '.$table.'.'.$column.'. Run the release migrations.', $failures);
                    }
                }
            }
            if (Schema::hasTable('billing_invoices')) {
                $this->check(! DB::table('billing_invoices')->where('payment_method', 'simulation')->exists(), 'The production database contains simulated billing payments.', $failures);
                if (Schema::hasColumn('billing_invoices', 'payment_mode')) {
                    $this->check(! DB::table('billing_invoices')->where('payment_mode', 'test')->exists(), 'The production database contains PayMongo test invoice records.', $failures);
                }
            }
            if (Schema::hasTable('billing_payment_attempts') && Schema::hasColumn('billing_payment_attempts', 'mode')) {
                $this->check(! DB::table('billing_payment_attempts')->where('mode', 'test')->exists(), 'The production database contains PayMongo test payment attempts.', $failures);
            }
            if (Schema::hasTable('paymongo_webhook_events')) {
                $this->check(! DB::table('paymongo_webhook_events')->exists(), 'The production database contains PayMongo test webhook receipts.', $failures);
            }
            if (Schema::hasTable('commerce_channels')) {
                $this->check(! DB::table('commerce_channels')->exists(), 'The production database contains simulated commerce channels. Keep local practice records separate from live commerce.', $failures);
            }
            if (Schema::hasTable('tenants')) {
                $this->check(! DB::table('tenants')->where('is_demo', true)->exists(), 'The production database contains a demo workspace.', $failures);
            }
            if (Schema::hasTable('failed_jobs') && DB::table('failed_jobs')->exists()) {
                $warnings[] = 'Failed queue jobs exist. Review and resolve them before launch.';
            }
        } catch (Throwable) {
            $failures[] = 'The database could not be checked. Review connectivity and permissions; credentials have not been displayed.';
        }
    }
}
