# Preparing a production POSONLY deployment

These files prepare a deployment; they do not create hosting, purchase services, or publish the application. The supplied Docker stack targets one host with PHP 8.4/Apache, MySQL 8.4, a queue worker, and a scheduler. An operator must supply the host, HTTPS reverse proxy, production secrets, email delivery, and backups.

**Verification limit:** the Docker daemon was stopped during implementation. The image and running Compose stack have not been built or tested here. Application tests and configuration checks are separate from testing the eventual server, database engine, email service, and counter hardware.

## Included files

| File | Purpose |
| --- | --- |
| `Dockerfile` | Production PHP image with required extensions and locked Composer dependencies; excludes development packages |
| `.dockerignore` | Keeps local secrets, databases, dependencies, caches, and logs out of the image |
| `.env.production.example` | Runtime configuration template with deliberately incomplete secrets/provider values |
| `deploy/compose.yml` | Web, worker, scheduler, and persistent MySQL services |
| `deploy/entrypoint.sh` | Caches runtime configuration and checks it before starting each application service |
| `deploy/apache.conf`, `deploy/php.ini` | Public document root and production server/PHP settings |
| `stockroom:preflight` | Read-only application configuration and database checks |

POSONLY serves its CSS and JavaScript directly from `public/`; its pages do not need a frontend build or Vite server.

## 1. Configure the environment

Run these commands from the repository root on the intended Docker host. Docker Engine and the Compose plugin must be available.

```sh
cp .env.production.example .env.production
chmod 600 .env.production
```

For an existing environment, preserve its file, application key, and database credentials. Edit the new file before starting services:

- Set `COMMERCE_SIMULATIONS_ENABLED=false`. All channels in this release are local simulations; the full preflight rejects databases containing them. Use separate production data and do not treat simulated orders, channel sales, or payments as live records.
- Set `BILLING_SIMULATIONS_ENABLED=false`. Simulated billing is local/testing only; do not copy its simulated paid invoice/subscription records into a production business. The full preflight rejects simulated invoice payment records.
- Set `PAYMONGO_ENABLED=false` and leave PayMongo secrets blank in production. This release supports local PayMongo test checkout only; live mode is blocked. Preflight rejects an enabled integration, test credentials even when disabled, test payment attempts/invoices, and any test-webhook receipts. Keep those records separate from production; do not relabel them as live paid subscriptions.
- Set `APP_URL` to the actual HTTPS origin and keep `APP_ENV=production`, `APP_DEBUG=false`, and `DEMO_ENABLED=false`.
- Set unique `DB_PASSWORD` and `MYSQL_ROOT_PASSWORD` values. The supplied stack uses `DB_HOST=database` and keeps MySQL off public host ports. Application containers receive an empty root-password variable.
- Configure a real SMTP host, scheme, port, credentials, and sender. Use your mail provider's values. `MAIL_MAILER=log` or `array` does not deliver reset emails and fails the production check.
- Keep `SESSION_DRIVER=database`, `CACHE_STORE=database`, and `QUEUE_CONNECTION=database` for this image. Database cache locks must be shared by web, worker, and scheduler. PhpRedis/Predis is not bundled.
- Keep the worker timeout below the queue retry interval: the template uses 60 and 90 seconds respectively.
- Keep `SESSION_SECURE_COOKIE=true` and use the same `APP_TIMEZONE` across services. The supplied interface displays Manila time; the template uses `Asia/Manila`.
- Set `TRUSTED_PROXIES` to your reverse proxy's actual IPs/CIDRs when applicable. An empty value trusts no proxy. Avoid trusting every address unless direct access to the application is restricted.

Do not commit `.env.production`, paste its contents into support requests, or use the sample values for a live environment. Store secrets through the host's protected runtime configuration when that is available.

## 2. Build and create a new application key

```sh
docker compose --env-file .env.production -f deploy/compose.yml build
```

For a **new environment only**, generate the application key with this operator command:

```sh
docker run --rm --entrypoint php stockroom:production artisan key:generate --show --no-interaction
```

Copy its output into `APP_KEY` in `.env.production` and store it securely. This command bypasses the normal entry point because the entry point correctly rejects an empty key. Preserve this key on every subsequent release; regenerating it invalidates encrypted data and sessions.

## 3. Migrate, check, and start

```sh
docker compose --env-file .env.production -f deploy/compose.yml up -d database
docker compose --env-file .env.production -f deploy/compose.yml run --rm --user www-data web php artisan migrate --force --no-interaction
docker compose --env-file .env.production -f deploy/compose.yml run --rm --user www-data web php artisan stockroom:preflight --no-interaction
docker compose --env-file .env.production -f deploy/compose.yml up -d web worker scheduler
```

The one-off commands wait for the database health check. Run the full preflight after migrations and correct every failure before opening the service. It checks production settings, a usable key, HTTPS URL, disabled demo/test integrations, shared stores, queue timing, recovery-mail configuration, database connectivity, required tables including monitoring/payment records, and staff-access schema. It also rejects a production database containing a demo workspace or practice payment records.

Each application's entry point performs `config:cache` after receiving runtime variables, then runs `stockroom:preflight --configuration-only`. That shorter check skips database connectivity and schema checks. Neither check sends mail or proves email delivery, TLS termination, backup recovery, queue processing, or payment integrations.

Check service health and recent logs:

```sh
docker compose --env-file .env.production -f deploy/compose.yml ps
docker compose --env-file .env.production -f deploy/compose.yml logs --tail=100 web worker scheduler
```

The web service binds to host loopback port 8081 by default. Put an HTTPS reverse proxy in front of it, forward the original host/protocol, and configure the trusted-proxy boundary. Secure cookies require the final HTTPS site for sign-in; plain HTTP on the internal port is only suitable for internal health checks. The supplied stack does not install a certificate or configure your DNS.

The worker processes low-stock alerts and replenishment draft checks. The scheduler dispatches `inventory:check-low-stock` every five minutes, reconciles subscription periods, and prunes old failed jobs daily. Low-stock alerts and replenishment targets use available stock after pending reservations. Replenishment generates reviewable purchase drafts only; it never sends supplier messages. Expired subscription write access blocks new drafts while alerts continue. Compose already supplies the scheduler; a second host cron runner is unnecessary. The worker periodically exits after one hour and its restart policy starts it again.

## Database and persistence choices

Named volumes preserve MySQL data and Laravel `storage/` on this Docker host. They are not backups. Preserve them across releases; `docker compose down --volumes` removes their data. Keep the application key with your recovery records.

The bundled Compose file runs MySQL. For managed MySQL or PostgreSQL, adapt the service dependencies and remove the bundled database service, then set the chosen driver and provider connection/TLS values. The image includes `pdo_mysql` and `pdo_pgsql`; PostgreSQL supports `DB_SSLMODE`. Verify migrations, locking, concurrent checkout, and backup restoration against the selected engine before accepting live sales. The existing local feature suite uses SQLite and does not establish MySQL/PostgreSQL runtime compatibility.

SQLite is suitable for a small, single-instance evaluation with persistent local storage. The standard container entry point rejects SQLite; use a conventional PHP setup for that evaluation and explicitly pass `--allow-sqlite` to preflight. Set `DB_DATABASE` to an absolute file path outside `public/`, create its parent directory, and create the file before migrating:

```sh
touch /absolute/persistent/path/stockroom.sqlite
php artisan migrate --force --no-interaction
php artisan stockroom:preflight --allow-sqlite --no-interaction
```

The application uses SQLite WAL mode. The database directory and its companion WAL/shared-memory files must be writable and persistent. Do not use an ephemeral file or share one SQLite file between hosts. Use a consistent SQLite backup process.

## Conventional PHP hosting

For a host that runs PHP directly, install PHP compatible with the lock file, Composer 2, and the necessary database extensions. Serve only `<release>/public`; make `storage/` and `bootstrap/cache/` writable by the application account. Keep code and secrets outside the public document root.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force --no-interaction
php artisan stockroom:preflight --no-interaction
php artisan optimize --no-interaction
```

Before these commands, create the environment file for a new installation using the production template and generate its key once. For updates, keep the existing environment and key. Run release commands as the application account so cache and log files remain writable.

Supervise `php artisan queue:work --tries=3 --timeout=60 --no-interaction` and run `php artisan schedule:run --no-interaction` every minute from the application root. Restart workers after deployment with `php artisan queue:restart --no-interaction`. Keep the worker environment identical to the web environment. Laravel's `php artisan serve` is a local development server.

## Account recovery and access

Recovery links use the configured `APP_URL`, expire after 60 minutes, and work only for active accounts outside the sample business. A successful password reset invalidates that user's previous database sessions and remembered sign-ins. Paused accounts must be reactivated by another administrator. Request notices stay generic even if the account does not exist or mail transport fails.

Test actual reset-email delivery to an account you control before launch; the automated tests fake notifications. Verify sender authentication and spam handling with your provider.

The supplied Apache configuration applies `Referrer-Policy: no-referrer` to all responses and writes request metadata such as method, protocol, final status, response bytes, and duration. Its access log omits request URLs, query strings, and Referer headers, avoiding dependence on front-controller rewrite conditions. Apply equivalent redaction to upstream proxy/CDN logs and do not expose recovery-email contents through shared server logs. Verify the actual server's logging and headers before launch.

Registration currently creates a business and owner without email verification or MFA. Team accounts are created directly with an initial password; invitation email is not implemented. Review public registration and administrative access for the intended launch.

## Release and launch checks

Run the application tests before release from a development or CI checkout with development dependencies installed. The production image excludes PHPUnit and the test files:

```sh
vendor/bin/phpunit
```

Then check the actual deployment with a test business:

1. Register, sign in/out, and recover a password through delivered email. Confirm anonymous access is rejected and old sessions cannot continue after reset.
2. Add a product, scan its saved barcode, and complete cash/split sales. Confirm totals and stock, including simultaneous attempts to buy the last unit.
3. Recover an interrupted checkout with **Check sale status** from the original browser tab; confirm the saved receipt and one stock deduction.
4. Reopen a receipt and return selected items, then the remaining units; verify original discount/tax allocations and no duplicate restock on retry. Receive a purchase order in two deliveries, verify history, approve a replenishment draft, cancel outstanding units, and transfer stock between locations.
5. Verify cashier/manager/administrator permissions and isolation between two businesses. Edit a staff role, pause/reactivate access, and check the activity filters.
6. Verify wholesale price validation, browser-held cart resume with current prices/stock, plan usage limits, expiry access, and return/export access after writes pause. Test cash-session counts if used. Exercise simulated renewal/payment and channel reservation/import/failure flows only in local/testing; production keeps those simulations disabled and needs verified live provider integrations before paid operation.
7. Test the real printer/scanner, queue processing, failed-job visibility, health checks, and a restored backup.

For an update, take a consistent database backup, build the new image, run migrations and full preflight as one-off release commands, then recreate web/worker/scheduler with the new image. Coordinate maintenance if a schema change is incompatible with the running release. Do not regenerate the key, reseed demo data, or run `migrate:fresh` on live records. A rollback must account for database compatibility as well as the image version.

## Operations and remaining integrations

Monitor uptime, exceptions, failed jobs, database capacity, and recovery-mail delivery. `/up` checks application boot; `/health` checks a database connection. Neither proves worker throughput or device/payment readiness. Schedule protected backups, test restores, maintain dependencies, and define access/retention rules for customer data, activity records, and logs.

Card and wallet tenders only record externally collected payments; actual collection and refunds require a provider. Offline sales, external e-commerce synchronization/webhooks, live subscription payment collection, automated supplier messages, and full accounting integrations remain unavailable. Partial deliveries, replenishment drafts, local subscription periods, invoices, plan limits, local billing simulation, and optional PayMongo test-invoice checkout are implemented. PayMongo test checkout/rechecks contact its API only with configured private test credentials. Signed test webhooks require a separately arranged public HTTPS endpoint; a localhost URL cannot receive them. No tunnel or provider account is provisioned. Rechecking an existing provider session can verify its test payment without webhook delivery. Invoice payments are individual, without card vaulting or automatic renewal collection, and a browser return is not payment confirmation. Test attempts and confirmations cannot serve as production paid records; live mode remains blocked. Commerce simulations reserve stock centrally, refresh local SKU catalog copies, and import orders once; they contact no external storefront or payment provider. Their channel/order/payment records also stay separate from production. Basic conversion-event records and CTA variant tagging are included; an analytics dashboard and statistical experiment reporting are not.

No production credentials, hosting account, domain, certificate, mail provider, or live deployment were configured during this implementation.

Framework references: [Laravel deployment](https://laravel.com/framework/docs/13.x/deployment), [queue workers](https://laravel.com/framework/docs/13.x/queues), and [mail configuration](https://laravel.com/framework/docs/13.x/mail).
