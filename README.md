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

## PayMongo billing

Hosted checkout pays individual subscription invoices. Local/testing environments use PayMongo test credentials; production can use live credentials, enabled payment methods, HTTPS return URLs, and a live webhook signing secret. POS tender records do not use this subscription-payment integration. Automatic recurring collection is not implemented.

For a local test, set these values in your private `.env`, then clear cached configuration and restart any local queue worker:

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

Hosted tests offer the configured test payment methods. Use the current examples in [PayMongo's payment-testing guide](https://docs.paymongo.com/docs/payment-acceptance-testing), then return and select **Recheck payment**; the invoice should show **Test payment confirmed**. Recheck again and confirm that the same invoice and paid-period dates remain unchanged.

Rechecking a known session works without a webhook or tunnel. Signed test events use `POST /webhooks/paymongo/test` and a private `PAYMONGO_WEBHOOK_SECRET`; live events use `POST /webhooks/paymongo` with their separate production signing secret. Localhost is not reachable from PayMongo; an operator must separately arrange public HTTPS forwarding and register a test endpoint. Webhook processing needs the configured worker. Automated and isolated acceptance tests disable live payments and fake external requests. See [PayMongo's API and webhook checklist](https://docs.paymongo.com/docs/developer-tools-go-live-checklist) for credential and delivery setup.

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
| `/app/billing` | Administrator plans, invoices, configured PayMongo checkout, payment records, and usage limits |
| `/app/settings` | Business checkout and receipt settings |
| `/offline.html` | Opt-in device catalog and queued cash sales; server confirmation is required |
| `/guide` | Searchable, printable staff manual |
| `/api/v1` | Versioned, device-token mobile API |

## Offline web support

In a signed-in workspace, open **Offline mode**, review the device-storage notice, and select **Enable on this device** on a trusted device. Select **Open saved POS** while connected once so its static files are ready, then use that page when the connection is unavailable. Use **Refresh saved catalog** while online before a shift and after changing prices, tax, stock, or cash sessions.

The device snapshot lasts 24 hours. It contains product names, SKUs/barcodes, variants, retail/wholesale prices, location stock/reservations, tax, receipt footer, company/account IDs, and the account's open cash-session IDs. It excludes costs, contact lists, team details, receipt history, billing information, provider credentials, passwords, and mobile bearer tokens. Browser storage is local to the device and is not a substitute for account authentication or an encrypted backup.

Offline checkout supports walk-in cash sales only. Choose the location and price tier, review the quoted amounts, enter the cash tender, and save the sale to this device's queue. Card, wallet, split payments, customer tagging, stock edits, purchases, returns, billing, and cash-session changes require an online connection. A queued sale is provisional: it has not yet received a server receipt, deducted central stock, or updated the server's cash drawer.

On reconnection, sign in as the same company user and select **Sync queued sales**. Each immutable queued request has one idempotency key. The server validates current access, available stock excluding reservations, quoted prices/tax/totals, and the original cash-session scope before saving the receipt and stock deduction together. A changed or closed shift, changed price/tax, stock shortage, or reused key is held for review rather than silently repricing or assigning the sale to another shift. A lost response is retried with the original key and payload.

Snapshots cannot guarantee stock availability across disconnected devices. Catalog expiry stops new offline checkouts; sales recorded within the valid period can still be submitted afterward. `created_at` and reports use server confirmation time. Receipts also expose the separate `offline.recorded_at` and `offline.synced_at` timestamps.

Use **Download queue backup** to keep a private recovery copy; exporting does not confirm or delete a sale. Sync queued sales before closing their original cash session. Signing out removes the active catalog and confirmed local history, while retaining unconfirmed cash sales and protected online checkout requests for the original company user. Sign back in on the same trusted browser to recover them. The normal **Clear device data** action is blocked while any owner has an unconfirmed request. A closed/changed cash session can require manual reconciliation; do not recreate the sale or reassign its shift. Keep a device with pending sales available until they are reconciled.

Only static offline assets are cached by the service worker. Authenticated workspace HTML, CSRF tokens, API responses, and billing/developer pages are not placed in its cache. Authentication or access failures never become a successful cached API response.

When changing any file listed in `ASSETS` in `public/service-worker.js`, update that worker's `CACHE` hash/version suffix and publish it with the changed assets. The new worker removes previous POSONLY shell caches when activated. Cache upgrades must preserve IndexedDB sale queues; do not clear unsynchronized records to refresh application files.

## Mobile API preparation

Laravel remains the authority for company data, authorization, prices, stock, cash sessions, and receipts. `routes/api.php` exposes `/api/v1` JSON endpoints for a future native app. The API does not include a native mobile interface, native Google/Apple sign-in, developer administration, or provider payment collection.

Create a device token by posting an existing company account's credentials to `/api/v1/auth/token`. Production requires HTTPS. Request example, using placeholders only:

```http
POST /api/v1/auth/token
Accept: application/json
Content-Type: application/json

{"email":"your-company-account@example.com","password":"<enter privately>","device_name":"Counter tablet"}
```

The `201` response supplies `token_type`, a one-time plain `token`, `expires_at`, and server-selected `abilities`. The defaults in `config/mobile-api.php` are a 30-day lifetime and at most 10 device tokens per account. Store the token in the native platform's secure credential store; never embed it in an app bundle, URL, analytics event, browser local storage, screenshot, or repository. Subsequent requests send it only in the `Authorization: Bearer <device-token>` header, with `Accept: application/json`. Do not send `tenant_id` or requested abilities during token creation; the account determines its company and role.

| Method and path, relative to `/api/v1` | Purpose |
| --- | --- |
| `GET /me`, `GET /bootstrap` | Current account, company, role, permitted abilities, locations, receipt settings, and access flags |
| `GET /devices`, `DELETE /devices/{token}`, `DELETE /auth/token` | List own devices, revoke an owned token, or revoke the current token |
| `GET /products`, `GET /products/{product}` | Paginated catalog or one company product, with variants, pricing, and location stock |
| `GET /settings/receipt-logo` | Receipt logo bytes; send the bearer header when fetching the protected image |
| `GET /customers`, `POST /customers` | Customer search and creation |
| `GET /sales`, `GET /sales/{sale}`, `POST /sales` | Permitted receipt history, one receipt, and server-calculated checkout |
| `GET /cash-sessions`, `POST /cash-sessions/open`, `POST /cash-sessions/{session}/adjustments`, `POST /cash-sessions/{session}/close` | Own location cash shifts and drawer reconciliation |
| `GET /offline/snapshot`, `POST /offline/sales` | Sanitized signed snapshot and queued cash-sale confirmation |
| `GET /reports/analytics` | Sales analytics within the account's permitted scope |
| `POST /sales/{sale}/return`, `GET /reports/financial` | Manager/administrator returns and financial summaries |
| `GET /suppliers`, `POST /suppliers`, `GET /purchase-orders`, `GET /purchase-orders/{order}` | Manager/administrator supplier and purchasing records |
| `POST /purchase-orders`, `POST /purchase-orders/{order}/approve`, `/receive`, `/cancel` | Manager/administrator purchase order lifecycle |
| `POST /products`, `PUT /products/{product}`, `POST /product-families`, `POST /product-families/{family}/variants` | Manager/administrator catalog and variant changes |
| `POST /stock/adjust`, `POST /stock/transfer` | Manager/administrator stock changes |

Product/contact lists support `page`, `per_page` (1–100), and `q`. Products also support `barcode` and `sku`; purchase lists support `status` and `location_id`. Paginated responses include `data` and pagination metadata. Cashiers can read their own sales; current roles and token abilities both constrain requests, and related IDs are always scoped to the authenticated company.

For checkout, stock adjustments/transfers, purchase creation/receiving, cash-session actions, partial returns, and offline confirmation, send a stable `idempotency_key` of 8–100 characters. Generate it once per intended action and keep the exact payload for retries after timeouts. Do not create another key to bypass an uncertain result or a conflict. Native clients must synchronize their cash outbox before closing its original cash session. The web client durably retains uncertain online checkout requests per account on the trusted browser and blocks offline checkout until they are confirmed. Online sale requests contain `location_id`, optional `customer_id`, `price_tier`, `items` (`product_id`, `quantity`), `discount_percent`, `tax_percent`, and `payments` (`method`, `amount`); money amounts are PHP decimal values, while quoted offline prices/totals below are integer cents.

Offline confirmation adds `permit`, an ISO 8601 `recorded_at` with a timezone, and this required quote to the cash-only sale request:

```json
{
  "expected": {
    "items": [{"product_id": 123, "unit_price_cents": 25000}],
    "subtotal_cents": 25000,
    "discount_cents": 0,
    "tax_cents": 3000,
    "total_cents": 28000,
    "cash_session_id": null
  }
}
```

The example is a quote fragment, not a complete checkout. Match every requested product exactly once and include `cash_session_id`, even when it is `null`. Do not supply customer details. A permit is a signed snapshot bound to the account; it does not authenticate API requests. A sale recorded during its 24-hour period can sync after that period. The server rejects future recording times beyond the configured five-minute clock allowance. Successful sale responses are plain receipt resources with `offline: {recorded_at, synced_at}` for offline confirmations and `offline: null` for normal receipts.

Handle `401` by asking the user to sign in again; `403` denotes denied access, `404` a missing/unavailable company record, `409` a conflict requiring review, `422` invalid fields or insufficient stock, `423` paused subscription/company writes, and `429` rate limiting. A network failure or `5xx` does not prove that a write failed: retry the same protected request. Tokens expire without an automatic refresh endpoint; reauthenticate to issue a new device token. Password reset revokes existing device tokens, and administrators can pause staff access. No live payment is needed to test these APIs in an isolated copy.

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
- Opt-in device catalog caching and a cash-only offline queue with signed snapshot periods, explicit synchronization, exact quote/shift checks, and provisional records until server confirmation.
- Versioned mobile API with finite, revocable, role-scoped Sanctum device tokens and company-scoped inventory/POS operations.
- Draft/approved supplier purchase orders, partial delivery receiving, receipt history, approval, and outstanding-order cancellation.
- Product/location/supplier replenishment rules that generate reviewable drafts while accounting for all outstanding quantities across suppliers.
- Local trial and monthly/annual subscription records, invoices, simulated payment confirmation, next-renewal plan changes, active staff/location limits, expiry access control, and full-history invoice CSV export.
- Optional PayMongo hosted checkout for individual subscription invoices, immutable payment-attempt records, verified dashboard-session recovery, server payment rechecks, and signed mode-specific webhook processing. Production live confirmations, provider tests, and local simulations stay distinctly labeled; automatic recurring charges are not implemented.
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

The same Laravel application supports local development and configured production deployment. Recording a card/e-wallet POS tender does not charge a customer; recording a return does not issue a refund. Held carts stay in the current browser/account and reserve no stock. The separate opt-in offline cash queue remains provisional until the server confirms it. Replenishment creates drafts for review and sends nothing to suppliers. Channels simulate catalog sync and paid orders locally; no external storefront or payment provider is contacted by those channels. Pending channel orders reserve central stock until import or cancellation; held/offline POS carts reserve no central stock.

Local billing simulation uses no provider; PayMongo test checkout contacts its test API and collects no live payment. Configured production PayMongo live checkout collects real payments for subscription invoices only. Production preflight rejects test credentials/records and simulated billing/channel data, and requires the live provider configuration when checkout is enabled. Keep isolated test records and credentials out of production.

Integrated POS payment collection/refunds, automatic subscription debits, external e-commerce sync/webhooks, supplier messaging, email invitation delivery, and full accounting require further integration. Offline purchases, returns, stock changes, card/wallet payments, and billing are not supported. Password recovery is implemented; its emails require a configured delivery service. A native mobile client must still be built against the prepared API.

Hardware support uses scanner keyboard input and browser printing. Direct printer commands, drawer control, and card-reader integration are not included. Test actual counter devices before live operation.

## Production preparation

[Dockerfile](Dockerfile), [the Compose stack](deploy/compose.yml), and [the production environment template](.env.production.example) prepare a single-host PHP 8.4/Apache + MySQL deployment with separate worker and scheduler services. `php artisan stockroom:preflight --no-interaction` checks production settings and database schema without changing data or displaying secrets.

Follow [the operator deployment guide](docs/DEPLOYMENT.md) for key creation, additive migrations, HTTPS, email delivery, persistence, and launch checks. Preserve the production application key and configuration, back up the app/database before a release, and never seed practice data into an existing company database. The Compose image and running stack require their own environment validation; repository preparation does not verify a running deployment.

## Verify

```sh
vendor/bin/phpunit
vendor/bin/pint --dirty --format agent
```

See [the staff manual](docs/USER_GUIDE.md) and [deployment instructions](docs/DEPLOYMENT.md). Never seed a demo business or publish local credentials in production.
