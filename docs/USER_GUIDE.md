# POSONLY staff guide

POSONLY brings inventory and point of sale into one browser workspace. This guide covers the features in this Laravel application. Open `/guide` for the searchable, printable staff manual.

To keep a copy, select **Print guide** and choose **Save as PDF** in your browser or operating system's print options. All guide sections expand for printing, including sections hidden by a search.

## Start a shift

1. Sign in with the email and password your administrator gives you.
2. Check the selected location before selling, counting, transferring, or receiving stock.
3. Check your scanner, printer, product prices, available stock, and business tax setting.
4. Choose your task from the left menu: Overview, Point of sale, Inventory, Purchasing, Customers, or Reports. Administrators also have Team, Activity, System health, Billing & plans, and Settings.

Use your own account. Sign out at the end of your shift. If an action is unavailable, ask your administrator to check your role.

## Recover your password

1. On the login page, select **Forgot password?**.
2. Enter your work email and select **Send reset link**.
3. Open the email and follow its link within 60 minutes. Enter and confirm a new password of at least 10 characters, then select **Save new password**.
4. Sign in again with the new password. Your previous sessions on other devices are invalidated.

The request message does not confirm whether an account exists or an email was delivered. Check spam and ask your administrator if no email arrives. Email delivery must be configured by the application's operator. Paused accounts require an administrator to reactivate them; sample-business accounts cannot use password recovery.

## Set up a new business

1. Register to create your business, administrator account, and Main store.
2. Review **Settings** for the tax rate and receipt text. Use **Add location** there to create another store or warehouse.
3. Add your products and opening stock in **Inventory**.
4. Add suppliers in **Purchasing → Suppliers**. If you choose **Create order** before adding a supplier, the app asks you to create one first.
5. Add individual staff accounts in **Team** and test one sale before opening the counter.

## Register or sign in with Google or Apple

1. Open registration and select **Continue with Google** or **Continue with Apple** when the provider is available. Complete sign-in and consent on the provider's own page; never enter your Google or Apple password into POSONLY.
2. On your first registration, confirm your name and enter the business name, then select **Create my workspace**. POSONLY creates your administrator account and Main store using PHP (Philippine peso). Review tax and receipt details in **Settings**.
3. For later visits, use **Continue with Google** or **Continue with Apple** on the login page with the same provider account.

If you already have a company account, sign in using your existing credentials first. Open **Sign-in accounts** from the sidebar. Administrators can also select **Manage sign-in accounts** in Settings. Select **Connect Google** or **Connect Apple** and complete the provider's sign-in. The connection opens your existing company with your existing role. Matching email addresses are not automatically merged. These sign-in options do not grant developer access.

Apple's **Hide My Email** may supply a private relay address instead of your normal email. Keep using the same Apple account. If you cancel consent or the sign-in expires, return to the login or registration page and start again. Recover a forgotten Google or Apple password through that provider.

Buttons labeled **Setup required** are unavailable until the application operator configures real provider credentials. Normal email/password registration and login remain available. No simulated provider identity is accepted.

### Operator: configure provider access privately

Use the empty variables in `.env.example` as a reference and add credentials privately to the local `.env`. Do not put client secrets or Apple private-key contents in chat, public folders, browser forms, or committed files. Clear stale configuration with `php artisan config:clear --no-interaction` after saving.

- **Google:** create a Web application OAuth client and configure its consent screen and test users as required. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI`. Register the exact callback URL with Google. For this local app the callback is `http://127.0.0.1:8010/auth/google/callback`; changing the host or port requires updating both Google and the local configuration.
- **Apple:** configure Sign in with Apple using a Services ID associated with your enabled primary App ID. Set `APPLE_CLIENT_ID` to that Services ID, plus `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY_PATH`, and `APPLE_REDIRECT_URI`. The key-path value points to the private `.p8` file outside `public`, `storage/app/public`, and any configured public storage directory. Register the callback domain and exact return URL with Apple. Apple requires a public HTTPS callback and does not accept this app's `127.0.0.1` address directly. The callback must share the HTTPS origin configured in `APP_URL`; open the application through that same registered HTTPS address before starting Apple sign-in so its session can be verified. Leave Apple unavailable until that secure test address is configured. Configuring credentials does not deploy the application.

Use Google's [OpenID Connect documentation](https://developers.google.com/identity/openid-connect/openid-connect) and Apple's [Sign in with Apple documentation](https://developer.apple.com/documentation/signinwithapple/authenticating-users-with-sign-in-with-apple) for provider configuration. Enable only the providers you have configured, then test consent, workspace registration, later login, and connecting an existing account.

## Make a sale

1. Open **Point of sale** and select the correct location.
2. Tap a product card, search by name or SKU, or scan a saved barcode into the search field. Choose a category to narrow the list.
3. Choose **Price tier → Retail prices** or **Wholesale prices**, then use **+ / −** in the cart to set quantities. Wholesale checkout requires a saved wholesale price for every product in the cart. Tag a customer if needed and apply the allowed discount percentage. The business's configured tax is added after the discount.
4. Check the subtotal, discount, tax, and total. Select **Charge**.
5. Choose cash, card, e-wallet, or split payment. Enter tender amounts and check the change.
6. Select **Complete sale** once and wait for confirmation. Stock is deducted when the sale succeeds.
7. In the receipt dialog, optionally select **Preview print layout** to inspect the document used by **Print receipt**. Select **Print receipt** to open the normal browser print dialog, choose your printer, and print.

The layout preview shows the same receipt document without opening the system print dialog. Receipt content is at most **72 mm** wide for **80 mm** paper. Select 80 mm paper in the printer driver and use 100% scale where appropriate. The preview is a layout proof; verify physical output with the actual printer.

Card and e-wallet entries record a payment. They do not charge a card or wallet. Use your separate provider or terminal first, confirm success, then record the tender. For a split payment, enter each part so the combined amount covers the total.

If the connection drops, keep the same browser tab open and reconnect. Select **Check sale status** in the payment dialog, or **Check sale** in Point of sale. The protected original cart and payment are retried together: POSONLY retrieves the saved receipt, or completes the original checkout once if it was not saved. Reloading the same tab can recover that checkout from browser session storage.

Never recreate or change the checkout while its result is unknown. Do not charge the customer's external card or wallet again. If POSONLY confirms no sale was saved and displays a validation error, close the dialog, fix the reported issue, and review the cart before trying again. A cart is not a stock reservation. An internet connection is required; this recovery is not an offline sales queue.

## Save a cart for later

1. Add items and select **Save cart**. Give it a recognizable name.
2. Open **Held carts** in the same browser and account. Select **Resume** to replace the current cart, or **Delete** to remove the saved cart.
3. Check the resumed cart's location, customer, price tier, quantities, current prices, and available stock before charging.

Held carts are saved on this browser/device for your account. They do not reserve stock, travel to other devices, or allow offline selling. Clearing browser storage removes them. A resumed cart uses current catalog prices; the server checks stock when completing the sale.

## Count your cash session

1. Open **Point of sale → Cash session** at the correct location. Enter your **Opening float** and select **Open cash session**.
2. For money physically added or removed, select **Cash in / out**, choose the direction, enter the amount and required reason, then select **Record cash movement**. Cash out cannot exceed the expected balance.
3. At shift end, select **Close & count**, enter the actual **Counted cash** and an optional count note, then select **Close cash session**.
4. Review the saved expected cash, count, and signed variance. Closed sessions keep their figures and cannot receive new movements.

Expected cash equals opening float plus your cash tenders after change, plus cash in, minus cash out. Card and wallet payments are excluded. Returns do not automatically remove cash: physically issue any refund through your store's process and record a cash refund separately as **Cash out**.

A session belongs to your own account and location. Checkout works without an open session. After an interrupted cash update, use **Check update status** in the same tab before starting another action. Opening a new session requires active subscription write access; counting/closing an existing session remains available after writes pause.

## Add or edit a product

1. Open **Inventory → Add product**.
2. Enter the product name, unique SKU, optional barcode, category, retail selling price, optional wholesale price, and cost.
3. Enter an optional variant description and its low-stock threshold. Use **Add variants** below for grouped size/color combinations.
4. Enter the actual opening stock at the selected location and save.

Use the product table menu → **Edit** to update catalog details. Check the saved price and barcode before using the item at checkout.

## Create and manage size/color variants

Managers and administrators can create a product family. Each combination has its own SKU, barcode, prices, and stock; selling a small blue shirt never uses a medium blue shirt's units.

1. Select **Inventory → Add variants**. Enter the family name, category, default retail/wholesale prices, cost, and reorder level.
2. Enter comma-separated **Sizes** and **Colors**, such as `S, M, L` and `Blue, Black`. Use either option or both. Choose an SKU prefix and the opening-stock location.
3. Select **Generate combinations**. Review up to 100 rows per batch. Edit each unique **SKU**, optional **Barcode**, **Selling price**, and **Opening stock**; remove combinations you do not sell. A SKU or barcode cannot belong to another product. Generating again replaces your row edits.
4. Select **Create variants** once. Review the separate SKU rows and counted stock before selling.
5. Select a family SKU's **Manage variants** to review siblings. **Add variants** adds new combinations using the family's fixed option types and skips existing combinations. New SKUs inherit the original family defaults; edit each saved SKU to change its cost, wholesale price, or reorder level.

Choosing **Create variants** from a standalone product prefills the builder. Its original SKU and stock stay standalone. Enter the new variants' actual quantities separately; no stock is copied or transferred.

Use a family SKU's **Edit** to change its Size/Color values and other catalog details. Its name is generated from the family name and options. Option types stay fixed, and combinations must stay unique. Labels can have up to 30 characters; the family name can have up to 80. **Illustration color** is a visual swatch, separate from the text Color option. Older free-text variant descriptions remain supported for standalone products.

Check the exact SKU/options at checkout, receiving, transfer, replenishment, and Channels. Receipts and pending online quotes retain their original labels and amounts after catalog edits. A return restores only the selected original SKU. If a batch response is interrupted, use **Check update status** in the same tab to retry its protected original request, including after a reload. Do not rebuild the batch while its result is unknown; the same confirmation cannot create duplicates.

## Read physical, reserved, and available stock

**On hand** is the physical stock at the selected location. **Reserved** is stock held for pending local online orders. **Available** equals on hand minus reserved and is the amount checkout, transfers, and negative adjustments may use.

For example: 10 units on hand minus 8 reserved leaves 2 available. Low-stock alerts and replenishment targets use available units. Inventory unit totals and valuation use physical stock.

## Count and adjust stock

1. Select the correct location and physically count the product.
2. Review recent sales, deliveries, returns, and transfers to understand any difference.
3. Open the product menu → **Adjust**.
4. Enter the change in physical units and a reason. For example, if the screen shows 10 but you count 8, enter **−2**, not 8.
5. Save and check the resulting stock.

Adjustments appear in stock movement history. A negative adjustment cannot reduce physical stock below reserved units. Review pending online orders and ask a manager to reconcile or cancel an unfulfillable order before correcting its stock. Do not use an adjustment to hide an unexplained difference.

A visible, idle workspace automatically refreshes every 20 seconds. Select **Refresh** to fetch records sooner. This is periodic refresh; instant stock push between browsers is not connected.

## Transfer stock

1. Select the source location in **Inventory**.
2. Open the product menu → **Transfer**.
3. Choose the destination and enter the positive quantity being moved.
4. Confirm and check both locations.

A transfer uses available units, subtracts from the source, and adds to the destination together. Reserved units cannot be transferred. This version records the transfer immediately; it has no in-transit or delivery-confirmation stage.

## Practice sales channels

Managers and administrators can open **Channels**. This is a local simulation: no storefront, marketplace, social network, payment provider, or webhook is connected, and no real payment is collected.

1. Choose the stock location, then select **Add channel**. Enter a name, choose Web store, Marketplace, or Social shop, and confirm the shared stock location. Its type and location stay fixed after creation.
2. Review **Catalog**. Central products map automatically by SKU; healthy channels publish available units from the shared location. Catalog edits, sales, returns, deliveries, transfers, and reservations update these local copies.
3. Select **Simulate paid order**, enter a unique **External order reference**, choose products and quantities, and save. The server saves the original retail prices and configured tax. Its pending order reserves units immediately; physical stock is unchanged.
4. Open **Orders → View order** and review the original quote. Select **Import order** to create its sales receipt once: reservation release and physical stock deduction happen together. The saved quote stays unchanged even if prices or tax settings change later.
5. Select **Cancel pending order** if it will not be fulfilled. This releases reserved units without reducing physical stock. An imported order must use its sale's return workflow in Reports instead.

Pending reservations protect units from POS, other channels, transfers, and negative adjustments. Importing 8 reserved units from 10 on hand changes the figures to 2 on hand, zero reserved, and 2 available; it does not deduct twice. Imported sales and exported records identify the simulated channel source. These practice payments must not be treated as real collections or refunded with real money.

The order view includes every pending order and the latest 200 handled orders; Sync history shows the latest 200 manual attempts.

**Pause channel** publishes zero units and stops new orders. Existing pending orders stay reserved and can still be imported or cancelled while the channel is paused, provided the action's subscription access allows it. **Resume channel** refreshes available stock and enables new orders. After subscription writes pause, reading, pausing, and cancelling pending orders remain available; imports and new orders pause.

## Check channel sync and recover an interrupted action

Select **Sync catalog** to record a manual snapshot in **Sync history**. Automatic stock/catalog refreshes do not flood that history. Published copies can be stale; the server still checks central availability before accepting each order.

For a practice failure, open **Manage channel**, enable **Practice a sync failure**, save, and sync. The channel shows failed/stale stock and keeps its previous published copy. Turn that setting off, save, and choose **Retry sync** for a new sync attempt. Other healthy channels continue updating; paused channels keep publishing zero.

If the response is interrupted, keep the same browser tab and select **Check update status**. Reloading that tab recovers the protected original channel/order request. Do not change the request, create another order, or start the next stage while its result is unknown. Confirmed failed-sync practice and an unconfirmed response are different: a confirmed failure needs a new attempt after fixing the setting; an unconfirmed action needs the original retry.

## Create, approve, and receive a purchase order

1. Check low-stock items in **Overview** or **Inventory**, then open **Purchasing → Create order**.
2. Choose the supplier and receiving location. Add products, ordered quantities, and unit costs.
3. Choose **Draft** if the order still needs review, or **Approved** to save it ready for receiving. A draft does not add stock; select **Approve order** after reviewing it.
4. Place the approved order with your supplier using your normal process. POSONLY does not send supplier messages.
5. Count a delivery, select **Receive delivery**, and enter only the units arriving in this delivery. Leave undelivered items at zero. Use **Receive all remaining** only when those units have actually arrived.
6. Add a delivery note, review quantities, and select **Record delivery** once. Check Inventory and **View details → Delivery history**.

Each delivery adds its own units once. The order stays **Partially received** until all units arrive; its details show received and remaining quantities and each delivery's staff member, time, and note. You cannot receive more than the remaining quantity.

Select **Cancel outstanding** in order details if the remaining delivery will not arrive. This preserves stock already received and the delivery history. A fully received order cannot be cancelled.

If a return or delivery response is interrupted, keep the same browser tab and select **Check update status**. Reloading the same tab can recover the protected original request. Do not start a new delivery or return while its result is unknown.

## Set replenishment rules

1. In **Purchasing**, select the correct location, then open **Replenishment rules → Add rule**.
2. Choose the product and supplier. Set a target of available units above the product's low-stock threshold and enable the rule.
3. Select **Generate drafts** to check enabled rules across all locations now. Background checks also generate drafts when queued stock updates or the five-minute schedule run.
4. Review new purchase drafts, select **Approve order**, then place the order with your supplier yourself.

Draft generation uses available stock plus remaining quantities on draft, approved, and partially received orders from all suppliers. It adds only enough units to reach the rule's target when available stock is at or below its threshold. Pending online reservations can trigger a draft before import; cancelling them frees availability but does not cancel an existing purchase draft. Drafts do not add stock or send supplier messages.

Use **Edit rule** to change the target and **Pause / Enable** to control generation. Cancelling an order releases its outstanding coverage: an enabled rule can create another draft at the next check. Pause the rule too if you want replenishment to stop. Stock-alert preferences and replenishment rules are separate; turning alerts off does not pause an enabled rule. Expired subscription write access stops new drafts while stock alerts still update.

## Return selected items or the remaining sale

Managers and administrators can return sales.

1. Open **Reports**, locate the original sale, and review its receipt.
2. Select **Return** and enter each item's quantity for this return, from zero to its remaining returnable units. **Return all remaining** selects all units that have not already been returned.
3. Check that selected items can go back into saleable stock. Enter a reason and review the exact refund preview.
4. Select **Record return** once. Only selected units are restored at the original sale location. Partial returns remain visible with their history; returning all remaining units marks the sale returned.
5. Handle the actual refund with cash or your external payment provider, following your store's process.

Refund amounts use the original receipt's prices, discount, tax, and rounding allocations. Current catalog prices do not change the refund, and successive partial returns cannot exceed the original sale total. The same confirmation is safe to retry and cannot restock twice. Use **Check update status** after an interrupted response.

POSONLY records the return; it does not transfer money. Damaged-item disposition is not integrated: do not restock an item that cannot be sold. A return does not automatically remove cash from a cash session; record an actual cash refund separately as **Cash out**.

## Add and tag customers

1. Open **Customers** and check whether the customer already exists.
2. Add their name and the contact details they agree to share.
3. Choose them in the POS cart before completing a sale.

Customer tagging is optional. A walk-in sale does not need a customer record. Creating a customer does not send marketing messages or create loyalty points.

## Review reports

1. Open **Overview** for sales, inventory, and low-stock summaries.
2. Open **Reports → Sales analytics** and choose **Today**, **Last 7 days**, **Last 30 days**, **This month**, **All time**, or **Custom range**. For a custom range, enter both **From** and **Through** dates. Both dates are included, in Manila time.
3. Choose **Report location**, including **All locations**, and **Sales source**: **All sources**, **Point of sale**, or **Simulated channels**.
4. Choose **Rank best sellers by**: **Net units sold** or **Net recorded sales**, then select **Apply filters**. **Refresh report** reloads the applied selection; **Reset filters** returns to the initial selection.
5. Read **Busy hours** and **Your best sellers, by SKU**. Use **Receipt** under **Matching receipts & returns** to review or reprint a transaction. Administrators can select **Export sales CSV** for every receipt matching the applied date, location, and source filters.

**Busy hours** counts receipts by their original sale hour across the selected dates. Returned receipts still count as customer traffic, and every hour tied for the highest count is marked as a peak. No sales means no peak hour.

**Net recorded sales** is the original total after discounts and tax, less recorded returns. Returns recorded later still reduce the period in which the original sale happened; this is not a report of refunds paid during the selected dates. **Net units** is sold units less returned units. Best sellers show up to ten SKUs with positive net units; each size/color variant ranks separately. Names and SKUs follow the latest matching receipt, and changing today's price never reprices old sales.

Analytics use the complete matching history, including receipts older than the latest 500 loaded into the receipt table. The table can therefore show fewer matches than the analytics. Cashiers see only their own sales; administrators and managers see the business's sales. Imported channel sales are labeled simulated and process no real payment. Pending or canceled channel orders are not sales and do not contribute.

Managers and administrators can check recent stock movements in **Inventory** when investigating a stock difference. Reconcile recorded tenders separately with counted cash and external payment-provider reports. These are operational figures, not profit, payment settlement, complete accounting, or statutory tax filing.

### Read financial reports

1. As an administrator or manager, open **Reports → Financial reports**. Cashiers keep access to their own **Sales analytics**.
2. Choose the Manila date range, **Report location**, **Sales source**, and **Report basis**, then select **Apply filters**. **Refresh report** reloads the last applied financial selection; each report tab keeps its own filters.
3. Review sales excluding tax, recorded tax, refunds, and the total after refunds. Open **Daily movement**, **By location**, **By sales source**, **Tax breakdown**, or **Original payment records** for detail. Administrators can select **Export financial CSV** for all sections using the last successfully applied filters and basis.

| Report basis | How returns are assigned |
| --- | --- |
| **Sales & refund dates** (default) | Sales use their sale dates; returns use the dates they were recorded. A period with only returns can have a negative total. |
| **Original sale dates** | Selects sales from the period and includes all their recorded returns, including returns made later. This follows the same sale-date principle as Sales analytics. |

For example, a sale on October 10 returned on October 11 contributes a return on October 11 under **Sales & refund dates**. Under **Original sale dates**, that return reduces October 10's sales. The report uses the original prices, discounts, and allocated tax; changing current prices or tax settings does not change old transactions.

**Estimated gross margin** subtracts known item costs from sales excluding tax, after returns. New POS sales capture the cost at checkout. New simulated channel orders capture it when the order quote is created, and retain that cost when imported. Returns use the original sale's cost. Older sales and existing pending channel orders are not backfilled. Check the cost coverage counts: known cost totals cover only units with snapshots, and margin shows **Unavailable** if any included sold or returned units lack them. A recorded zero cost is valid; current catalog costs are never substituted.

**Original payment records** describes sale tenders before returns. Cash change is deducted from cash only; card and e-wallet entries remain as recorded. Refunds stay unallocated by payment method because POSONLY does not record their actual payment destination or provider settlement. Reconcile actual refunds and drawer movement separately.

Check **Historical record coverage** for older return or tender gaps. A legacy return uses its recorded return timestamp when available. Undated legacy returns are disclosed and excluded from **Sales & refund dates**, even for All time; **Original sale dates** can include them with their sale. These notes follow the selected location/source when a return date is unknown.

Financial reports use the full matching history. Simulated channel sales and returns are identified and move no real money. Margin is an estimate before operating expenses; these reports do not establish net profit, supplier payments, cash-drawer settlement, subscription expenses, or statutory tax liability.

## Manage team and settings

Administrators can open **Team** to add a name, email, temporary password, and role. The form creates an account directly; it does not send an invitation email. Share credentials privately.

To change a staff member's name or role, select **Edit access**, review the details, and select **Save team member**. The new permission level applies to their next workspace request.

To stop a staff member using the workspace, select **Pause access** and confirm. This keeps their sales and activity records. Select **Reactivate** and confirm when they should return; reactivation keeps their existing password. Keep at least one active administrator. Another administrator must change your own role or pause your access.

| Role | Everyday responsibilities |
| --- | --- |
| Cashier | Checkout, customer creation, catalog/stock viewing, and their own sales |
| Manager | Sales, inventory changes, transfers, suppliers/locations, purchasing, returns, and operational reports |
| Administrator | Manager tasks, team accounts/access, activity logs, System health, business settings, and CSV exports |

Open **Settings** as an administrator to review the single checkout tax rate, receipt footer, and stock-alert preference. Save changes before using them for live sales. Product-specific tax rules and regional tax engines are not included.

### Customize company receipts

1. In **Settings**, enter your company's receipt address and contact number. Leave optional details blank when they should not appear.
2. Upload a logo from your device: PNG, JPEG, or WebP, up to **1 MB**, with each dimension no larger than **2,000 pixels**. You can remove the current logo instead.
3. Select **Save settings**, then open a receipt and select **Preview print layout** to check the logo, address, and contact number. Use **Print receipt** for the printer check.

Only administrators can change this company's receipt branding. The logo is kept in private local storage, and blank optional fields are omitted. The company name remains its registered name.

All receipt previews and printouts use the latest saved profile, including receipts from earlier sales. Address, contact number, and logo are not frozen when a sale is completed.

## Billing, plans, and test payments

Administrators can open **Billing & plans** to review the trial, subscription dates, locations, active staff usage, and local invoices. The screen shows the latest 100 invoices; **Export invoice CSV** includes the complete invoice history.

1. Choose **Monthly** or **Annual**, review the plan's limits, and select **Choose this plan**. An active paid period uses **Schedule this plan**; that change takes effect at the next renewal.
2. Select **Create invoice** when available, then **View invoice**. With local simulation selected, **Simulate payment** starts a simulated monthly or annual paid period. With PayMongo test mode configured, use the hosted-checkout steps below.
3. Use **Cancel renewal** to stop renewal at the end of the current trial or paid period. **Resume renewal** clears cancellation; it does not collect payment.

Both options are for local/testing practice and collect no live money. Local simulation contacts no provider; PayMongo test checkout contacts its API only when the operator supplies a private test key. Renewals create invoices and do not collect money automatically. Local simulations and PayMongo test confirmations must not be imported or relabeled as real paid subscriptions in production.

### PayMongo test checkout

An operator enters the private `sk_test_...` key in the project's local `.env` and saves it before refreshing configuration. Do not paste that key into chat or staff support messages. Card and GCash are the supported hosted test options.

1. Open **View invoice → Pay with PayMongo (test)** to prepare its checkout. Choose **Open PayMongo test checkout** to open the hosted page. Use test payment details, never a live charge.
2. For a successful Card test, enter Visa **4343434343434345**, an expiry after today, and a CVC of three digits. These details come from [PayMongo's payment-testing guide](https://docs.paymongo.com/docs/payment-acceptance-testing).
3. Return to Billing and select **Recheck payment**. Only server verification can mark the invoice **Test payment confirmed** and start its period. Recheck again to confirm the same invoice and paid-period dates stay unchanged. A success URL, browser message, or canceled checkout tab does not prove payment or cancel the local invoice.
4. If confirmation is uncertain and the session is known, select **Recheck payment** to retrieve its status. If creation is uncertain and no session ID was saved, do not create it again. Select **Recover checkout** and enter the **Checkout session ID** copied from the PayMongo test dashboard. The server checks that session against the saved invoice reference, amount, PHP currency, and test mode before accepting its link or payment.

Repeating checkout creation can produce another session, even with the same reference. If the uncertain session cannot be located, leave the attempt unresolved and reconcile it with the provider. A confirmed failed or expired attempt may offer **Start test checkout again** after server validation. **Needs review** requires operator reconciliation before further payment.

Without a test key, the payment action is disabled and the screen explains what is missing. Rechecking a known session needs no webhook or tunnel. Automatic test-event confirmation needs an operator-configured public HTTPS forwarding endpoint, its private webhook secret, and a running worker; localhost alone cannot receive PayMongo events. This application does not create a tunnel or configure a provider account. Card vaulting, automatic recurring debits, live checkout, and provider refunds are not implemented.

Invoice and payment records distinguish **Local simulation / Simulated · paid** from **PayMongo · test / Test payment confirmed**. **Test checkout history** shows the latest 100 attempts independently of the latest 100 invoices; select **View attempt** to inspect a recent attempt for an older invoice. Exported invoice history includes the provider, mode, and safe checkout/payment references, without credentials or hosted checkout URLs.

Resolve waiting, uncertain, or review-required checkouts before changing plans or canceling renewal. These actions are blocked while an outstanding attempt could still confirm payment.

Limits apply to the number of locations and active staff, including the administrator. Paused staff do not count; reactivation requires room in the plan. A plan change must fit current usage. Changing the trial plan does not restart its original trial clock.

A missed renewal or expired trial has a seven-day grace period. Afterwards, new sales and core catalog/stock changes pause, including new replenishment drafts. Records stay available, and permitted returns, exports, order cancellation, and billing remain available. Voluntary cancellation keeps access until the stated period end and has no extra grace period. Review the access end date shown in Billing & plans.

## Review staff activity

1. Open **Activity** as an administrator.
2. Choose a team member, action, and optional From/To dates, then select **Apply filters**. The log covers all locations in your business.
3. Check the time, staff member, action, and record reference. Use **Previous / Next** for other pages, **Refresh activity** for new entries, or **Reset** to clear filters.

This log records successful workspace actions from the time activity logging was enabled. It does not backfill older transactions or record every sign-in, failed action, or page view. Repeated checkout, return, or delivery confirmations can create multiple log entries for the same transaction; they do not mean the stock changed twice. Passwords, payment details, request contents, and recovery tokens are excluded from this log.

## Check system health

1. Open **System health** as an administrator and select **Refresh health** to read the current checks. Database, cache, private storage, and log-directory checks run against this application. Worker and scheduler readings use shared infrastructure heartbeats; notices and background-check records belong only to your business.
2. Select **Run background check** to queue a harmless check that confirms the diagnostic job ran. Refresh to see its result. A pending check is reused instead of queuing another; new attempts are limited to one per 60 seconds. If the request result is uncertain, use **Check diagnostic status**. A check that waits a long time stays pending, so ask the operator to check the configured worker rather than assuming it failed or repeating a business transaction.
3. Review any local incident and investigate its cause. Select **Acknowledge incident** when you have reviewed it. This preserves the notice and does not fix the error, delete a failed job, or retry one.

A missing or stale heartbeat needs investigation; it is not proof that every job has failed. A fresh heartbeat only confirms that the process recently reported. With a synchronous queue, there is no separate worker pulse to verify. Ask the operator to check the local worker and scheduler processes when these readings are unavailable or stale.

System health exposes sanitized notices, not raw logs, exception messages, file paths, or request secrets. Recent notice/check lists are limited to 50 records each. Notices and completed checks use 30-day retention; outstanding pending checks remain visible until completed. Billing integration cards report configuration and test mode; refreshing health does not contact PayMongo or verify provider connectivity. This screen does not connect an external monitoring service or send email, SMS, or push alerts. It remains available to administrators after subscription access expires.

## Use the developer portal (platform owner)

Developer accounts are separate from company staff accounts. Registering a business or assigning an Administrator role does not grant developer access. The portal is available at `/developer/login`; company staff continue to use their normal login. Both sessions can coexist, and signing out of one leaves the other signed in.

### Set up the first developer account

The application's operator runs this command locally from the project directory, substituting the owner's email and name:

```sh
php artisan platform:invite owner@example.com --name="Platform owner" --no-interaction
```

Open the generated private `storage/app/private/developer-setup.html` file locally and follow its setup link within 24 hours. Choose and confirm a password of at least 12 characters, then sign in at `/developer/login`. The link works once; delete the private file after setup. Keep the file and link out of chat, public folders, and shared repositories. Running the command again for a pending account replaces its previous link; an activated account cannot be overwritten this way. No invitation email is sent.

### Review a company and its subscription

1. Open **Companies**, search for the business, and select **View company**. Review **Company overview**, **Invoices**, or **Payment attempts** before making a change.
2. Choose **Pause operations / Resume operations**, **Change plan**, **Cancel renewal / Resume renewal**, or **Create invoice**. Check the affected company and enter the required reason of 3–300 characters before confirming.
3. Review the resulting state and **Platform activity**. Each successful change records the developer, company, reason, and before/after state.

Pausing operations stops new sales and core stock changes while preserving records, reports, permitted returns, and billing/reconciliation access. It does not restart a trial or change paid-period dates. Resuming operations removes that pause; normal subscription expiry and plan limits still apply. Plan changes must fit all locations and active staff; a paid-period change is scheduled for the next renewal. Resolve an outstanding PayMongo test checkout in the company workspace before a conflicting billing change.

**Subscriptions & invoices** shows recorded local invoices, with simulation and PayMongo test confirmations labeled separately. **Create invoice** creates an open record without collecting payment or activating a paid period. Canceling/resuming renewal changes local renewal records and does not debit a card. The developer portal cannot start or recheck provider payments or manually mark an invoice paid; its totals are not live revenue or MRR. Opening lists or company details does not reconcile or change billing records.

### Edit the local website

Open **Website content** to edit the available title, description, hero text, button label, footer tagline, and support email. Select **Save draft**, enter a reason, and use **Preview saved draft** to review it. Select **Publish to local site** with a reason to make that saved draft visible on this running website; this does not deploy the application. Save typed edits before publishing. If another session changed the draft, your typed text stays available: use **Review saved version** and confirm any discard/reload before replacing it. Plan pricing and page layout remain controlled by the application.

## Set up counter hardware

| Device | Setup |
| --- | --- |
| Computer or touch tablet | Use a current browser and a reliable connection. Test the workspace at the counter's normal screen size. |
| Barcode scanner | Use keyboard mode, connect or pair the device, focus the POS search field, and scan a barcode already saved on a product. |
| Receipt printer | Install the manufacturer's driver, choose 80 mm paper, print a test page, and select the device in the browser print dialog. Check margins and use 100% scale where appropriate. |
| Cash drawer | Open manually or configure it through the printer's own tools. Direct drawer control is not integrated. |
| Card/contactless terminal | Process payments with a separate approved provider. Record successful payments in POSONLY. |

Test actual devices before opening your counter. Browser printing is included; direct printer commands and automatic cash-drawer opening are not.

## Quick troubleshooting

| Problem | First steps |
| --- | --- |
| Return or delivery did not finish | Keep the same tab, reconnect, and use **Check update status** to retry its protected original request. Do not create a new request while the result is unknown. |
| Sales and stock changes are paused | Ask an administrator to check **Billing & plans**, its access end date, and the open local invoice. If the message says operations were paused by the platform administrator, ask the platform owner to review **Companies**. Existing records remain available. |
| Sale did not finish | Keep the same tab open, reconnect, and use **Check sale status / Check sale** to retry the protected original checkout. Reloading that tab can recover it. Never recreate the checkout while its result is unknown. |
| Barcode not found | Check scanner keyboard mode, search-field focus, and the saved barcode, including leading zeroes. Search by name or SKU meanwhile. |
| Stock looks wrong | Check the selected location, count actual units, and review stock movements before adjusting. |
| Receipt will not print | Check printer power, connection, paper, driver, and print queue. Reopen the saved receipt in Reports. |
| Action is unavailable | Read the validation message, check required fields, and ask your administrator to verify your role. |
| Background check stays pending or a heartbeat is stale | Ask an administrator to open **System health**, refresh its readings, and have the operator check the local worker or scheduler. Acknowledging a notice does not repair it. |
| Cannot sign in | Use **Forgot password?** with your work email. Ask your administrator if access is paused or no reset email arrives. Do not share another staff member's account. |

## Current boundaries

This application stores its core transactions in the Laravel database. It is not a production cloud deployment by itself. The following require additional implementation or provider integration:

- External e-commerce/marketplace synchronization and live stock push between browsers. Local channel catalog/order simulations and central reservations are implemented.
- Offline sales and conflict resolution.
- Charging or refunding cards and wallets.
- Live subscription checkout and recurring payment collection. Local plans, invoices, simulations, optional PayMongo test checkout/rechecks, access periods, and limits are implemented. Test records are not real paid subscriptions.
- Sending supplier orders or messages automatically. Replenishment drafts and partial delivery history are implemented.
- Email invitations, loyalty programs, and full accounting integrations.

See [DEPLOYMENT.md](DEPLOYMENT.md) for deployment requirements.
