# POSONLY

A Laravel inventory and point-of-sale workspace with a product landing page and a searchable staff manual. Built for straightforward daily tasks: sell, receive, count, transfer, and review.

## Run locally

Requires PHP and Composer. This repository uses Laravel 13; development was performed with PHP 8.4. Dependencies are pinned in `composer.lock`.

```sh
composer install --no-interaction
cp .env.example .env
php artisan key:generate --no-interaction
touch database/database.sqlite
php artisan migrate --seed --no-interaction
php artisan serve --host=127.0.0.1 --port=8010
```

For an existing checkout with a configured `.env`, preserve its application key and database configuration instead of copying or regenerating them. SQLite is the default local database; ensure its configured database file exists and is writable before migration.

To enable the local practice-business entry, set `DEMO_ENABLED=true` in `.env` with `APP_ENV=local`. The public demo entry is disabled outside local/testing environments. Seed data is intended for development only.

Use the landing page's **Explore demo** action to enter the practice business. Demo passwords are randomly generated; there are no shared login credentials. Register to create a separate, empty business instead.

The app serves its CSS and JavaScript from `public/`; no frontend build is needed for the POSONLY pages. For queued low-stock jobs and diagnostic checks, run a worker in another terminal:

```sh
php artisan queue:work --tries=3 --no-interaction
```

Run `php artisan schedule:work --no-interaction` during local development to refresh stored low-stock alerts, generate enabled replenishment drafts, reconcile local subscription periods, report scheduler heartbeats, and prune expired monitoring notices. For production, configure the scheduler runner described in the deployment guide.

Administrators can inspect **System health** in the workspace. Operators can also run `php artisan monitoring:check --no-interaction` for sanitized infrastructure summaries; it reports actual checks and does not process business transactions. A synchronous queue runs diagnostics inline and cannot verify a separate worker heartbeat.

## PayMongo test billing

Hosted subscription-invoice checkout is supported only in local/testing environments with PayMongo test credentials. Set these values in your private `.env`, then clear cached configuration and restart any local queue worker:

```dotenv
BILLING_PAYMENT_PROVIDER=paymongo
PAYMONGO_ENABLED=true
PAYMONGO_MODE=test
PAYMONGO_SECRET_KEY=sk_test_REPLACE_WITH_YOUR_PRIVATE_TEST_KEY
PAYMONGO_WEBHOOK_SECRET=
```

Open the project's private `.env` locally, replace the `PAYMONGO_SECRET_KEY` placeholder with your `sk_test_...` key, and save the file. Do not paste the key into chat or include it in source control, screenshots, or support messages. No public API key is required by this hosted-checkout flow. With a blank secret key, checkout remains disabled. For local simulation instead, select `BILLING_PAYMENT_PROVIDER=simulation` and enable `BILLING_SIMULATIONS_ENABLED`.

After changing private local configuration, run `php artisan config:clear --no-interaction` and `php artisan queue:restart --no-interaction` if a worker is running. The restart signal stops the old worker; without a process manager, start it again with `php artisan queue:work --tries=3 --timeout=60 --no-interaction`.

In **Billing & plans**, create an invoice, open **View invoice**, and select **Pay with PayMongo (test)**. **Open PayMongo test checkout** opens its hosted page; **Recheck payment** retrieves the known provider session and verifies it on the server. Returning to POSONLY does not itself prove payment. Renewals create individual invoices; this does not vault cards or automatically debit future payments. Test confirmations move no live money and must stay separate from production records.

If checkout creation is uncertain and no session ID was saved, do not create it again. Use **Recover checkout** and enter the **Checkout session ID** copied from the PayMongo test dashboard. The server retrieves that session and verifies the saved invoice reference, amount, PHP currency, and test mode before accepting its link or payment. If the session cannot be located, leave the attempt unresolved and reconcile it with the provider. Repeating a creation request is not guaranteed to reuse a session.

Hosted tests offer **Card** and **GCash**. For a successful card test, choose Card and enter Visa **4343434343434345**, an expiry after today, and a CVC of three digits, as listed in [PayMongo's payment-testing guide](https://docs.paymongo.com/docs/payment-acceptance-testing). Return and select **Recheck payment**; the invoice should show **Test payment confirmed**. Recheck again and confirm that the same invoice and paid-period dates remain unchanged.

Rechecking a known session works without a webhook or tunnel. Optional signed events use `POST /webhooks/paymongo/test` and a private `PAYMONGO_WEBHOOK_SECRET`. Localhost is not reachable from PayMongo; an operator must separately arrange public HTTPS forwarding and register a test endpoint. No tunnel, provider account, or deployment is created by this repository. Webhook processing needs the configured worker. Provider HTTP tests use fakes; live checkout and payments remain blocked. See [PayMongo's API and webhook checklist](https://docs.paymongo.com/docs/developer-tools-go-live-checklist) for credential and delivery setup.

## Pages

| Path | Purpose |
| --- | --- |
| `/` | Product landing page, feature walkthrough, and plan comparison |
| `/register` | Create a business and administrator account |
| `/login` | Sign in |
| `/forgot-password` | Request an emailed password reset link |
| `/app/overview` | Business overview |
| `/app/pos` | Touch-friendly checkout |
| `/app/inventory` | Catalog, location stock, adjustments, and transfers |
| `/app/purchases` | Purchase orders and stock receiving |
| `/app/channels` | Local web-store, marketplace, and social-shop simulations, catalog snapshots, reservations, and order imports |
| `/app/customers` | Customer records |
| `/app/reports` | Full-history sales analytics, busy hours, best-selling SKUs, receipts, and partial returns |
| `/app/reports?view=financial` | Administrator/manager sales, refund, tax, tender, and cost snapshot summaries |
| `/app/team` | Staff accounts and roles |
| `/app/activity` | Administrator activity history with filters and pagination |
| `/app/monitoring` | Administrator system checks and business-scoped diagnostic notices |
| `/app/billing` | Administrator plans, invoices, local simulation or PayMongo test checkout, payment records, and usage limits |
| `/app/settings` | Business checkout and receipt settings |
| `/guide` | Searchable, printable staff manual |

## Implemented scope

- Session authentication, email-based password recovery, and separate business data.
- Administrator, manager, and cashier roles enforced by the backend.
- Product SKUs, barcodes, categories, retail/wholesale prices, costs, and low-stock thresholds; size/color families create up to 100 independent sellable SKUs per batch with duplicate-safe creation and append retries. Existing standalone products and their stock remain separate when used to prefill a family.
- Variant matrix review, sibling management, and fixed family option types with editable per-SKU values. Original variant labels and prices remain on historical receipts and pending channel quotes; stock, reservations, transfers, deliveries, returns, and replenishment stay per SKU. Legacy variant descriptions and illustration swatches remain supported.
- Location-specific physical, reserved, and available inventory; conditional stock deductions prevent POS/counts/transfers from consuming pending online reservations. Low-stock checks and replenishment use available units; valuation uses physical stock.
- Server-calculated checkout, discounts, configured tax, cash/card/e-wallet/split tender records, and printable receipts.
- Transactional stock deduction and partial/full returns using original receipt amounts, with return history and duplicate-safe confirmation retries.
- Duplicate-safe checkout retries, with original cart/payment recovery from the same tab's session storage.
- Optional per-user/location cash sessions with opening float, cash in/out, immutable closing count, and variance; actual cash refunds need a separate cash-out record.
- Browser/account-scoped held carts with current price and stock review on resume.
- Draft/approved supplier purchase orders, partial delivery receiving, receipt history, approval, and outstanding-order cancellation.
- Product/location/supplier replenishment rules that generate reviewable drafts while accounting for all outstanding quantities across suppliers.
- Local trial and monthly/annual subscription records, invoices, simulated payment confirmation, next-renewal plan changes, active staff/location limits, expiry access control, and full-history invoice CSV export.
- Optional PayMongo test hosted checkout for individual subscription invoices, immutable payment-attempt records, verified dashboard-session recovery, server payment rechecks, and signed test-webhook processing. Test provider payments and historical local simulations remain distinctly labeled; live collection and automatic recurring charges are blocked.
- Local sales channels with SKU-mapped catalog copies, recorded manual syncs/failure practice, immutable simulated orders, central reservations, import-once sales, and cancellation.
- Customer tagging, operational reports, staff name/role editing, access pause/reactivation, and business settings.
- Administrator activity history of successful workspace actions, with member/action/date filters and pagination.
- Administrator System health with actual database, cache, private storage, and log-directory checks; shared worker/scheduler heartbeat readings; and business-scoped sanitized errors and queued diagnostic checks. Repeated pending checks are reused. Notices and completed checks use 30-day retention; outstanding pending checks remain visible until completed. Acknowledgment marks a notice as reviewed and does not repair errors, retry failed jobs, or send notifications.
- Server-calculated sales analytics across the complete matching receipt history, with Manila date ranges, location/source filters, all 24 hourly traffic totals, tied peak hours, and the top 10 SKUs ranked by net units or net recorded sales. Returns use original receipt amounts and remain attributed to the original sale period; variants rank independently with receipt snapshot labels. Cashiers see their own sales; administrators can export the full matching history as CSV.
- Read-only financial reports for administrators/managers with sale/refund-date or original-sale-date reporting, exact original discount/tax credits, daily/location/source/tax-rate breakdowns, original tender records after cash change, and administrator CSV export. New POS receipts and simulated channel order quotes retain item cost snapshots; margin estimates use those snapshots and remain unavailable when relevant units lack costs. Existing sales and pending channel orders are not backfilled. Historical return-date and tender gaps are disclosed; actual settlements, refund destinations, operating expenses, and net profit are not calculated.
- Automatic refresh of a visible, idle workspace every 20 seconds.
- Basic server-side conversion event records and two CTA variants on the landing page.
- Accessible staff guidance, reduced-motion support, and a silent interface.

## Integration boundaries

This is a working local application, not a deployed production cloud service. Recording a card/e-wallet tender does not charge a customer; recording a return does not issue a refund. Held carts stay in the current browser/account, reserve no stock, and do not enable offline sales. Replenishment creates drafts for review and sends nothing to suppliers. Channels simulate catalog sync and paid orders locally; no external storefront or payment provider is contacted by those channels. Pending orders reserve central stock until import or cancellation; held POS carts reserve nothing. Local billing simulation uses no provider; configured PayMongo test checkout contacts its test API but collects no live payment. Neither simulated nor provider-test payments are real paid subscriptions. Production preflight rejects enabled PayMongo checkout, test credentials, test attempts/invoices/webhook receipts, simulated billing payments, and simulated channel data.

External payment collection/refunds, live subscription checkout and recurring collection, offline operation, external e-commerce sync/webhooks, supplier messaging, email invitation delivery, and full accounting require provider integration or further implementation. Password recovery is implemented; its emails require a configured delivery service.

Hardware support uses scanner keyboard input and browser printing. Direct printer commands, drawer control, and card-reader integration are not included. Test actual counter devices before live operation.

## Production preparation

[Dockerfile](Dockerfile), [the Compose stack](deploy/compose.yml), and [the production environment template](.env.production.example) prepare a single-host PHP 8.4/Apache + MySQL deployment with separate worker and scheduler services. `php artisan stockroom:preflight --no-interaction` checks production settings and database schema without changing data or displaying secrets.

Follow [the operator deployment guide](docs/DEPLOYMENT.md) for key creation, one-off migrations, HTTPS, email delivery, persistence, and launch checks. No hosting was created or service deployed. Compose configuration and entrypoint shell syntax were checked; the image and running stack remain untested because the local Docker daemon was stopped.

## Verify

```sh
vendor/bin/phpunit
vendor/bin/pint --dirty --format agent
```

See [the staff manual](docs/USER_GUIDE.md) and [deployment instructions](docs/DEPLOYMENT.md). Never seed a demo business or publish local credentials in production.
