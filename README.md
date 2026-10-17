# Ovajj Payment Gateway for WooCommerce

Version 1.1.0. Built against the official Ovajj API documentation (https://ovajj.com/api-docs, read 17 Oct 2026).

> **Status: v1.0 was reported working by the site owner in sandbox. The v1.1 currency-conversion code is new and has not been run.**
> (Original note: The sandbox used to build this plugin had no PHP interpreter and no network, so the code
> has been reviewed by hand but **not linted, executed, or tested against a live WordPress/WooCommerce or the Ovajj
> sandbox**. Run `php -l` on every file and `php tests/run-tests.php` first, then complete the manual checklist below
> in **sandbox** before using production.)

## Requirements
WordPress 5.8+, WooCommerce 6.0+ (HPOS needs 7.1+), PHP 8.1+, HTTPS (required in production), an Ovajj merchant account.

## Install
1. WordPress Admin → Plugins → Add New → Upload Plugin → choose `ovajj-payment-gateway.zip` → Install → Activate.
2. If WooCommerce is missing/inactive the plugin shows an admin notice and does nothing else (no fatal errors).

## Configure (sandbox first)
WooCommerce → Settings → Payments → **Ovajj**.

| Setting | Notes |
|---|---|
| Environment | Sandbox or Production. Only that environment's three credentials are used; there is no fallback to the other set. |
| Sandbox / Production Merchant Key, API Key, API Secret (Client Secret) | From Ovajj Dashboard → Merchant → Config. Secrets are never printed back into the page; leave blank to keep, `-` to clear. |
| Allowed payment methods | Optional `allow_payment_methods` filter, e.g. `stripe,paypal`. Only narrows what you enabled in Ovajj → Config → Payment Gateway Controls. |
| Send customer details | Sends name/email as optional `customer_name` / `customer_email`. |
| Reference prefix | Prefix for `ref_trx` (default `WC`). Use different prefixes if several stores share one merchant account. |
| Debug log | Verbose logging. Errors and key events are always logged. Secrets are never logged. |
| Delete settings on uninstall | Off by default. Order meta is never deleted. |

Key-prefix guard: per the docs, sandbox Merchant/API keys start with `test_` and production keys do not. Saving a key in
the wrong environment field is rejected. Disable with the `ovajj_enforce_key_prefixes` filter if your keys differ.

On save the plugin calls `GET /api/v1/site-info` to confirm the credentials work and caches the logo it returns.

**Before live traffic** (from the Ovajj checklist): merchant approved, an active wallet for every currency you sell in,
the gateways you want selected in Payment Gateway Controls, HTTPS success/cancel URLs, production keys entered.

## Currency conversion (new in 1.1)
Use it when Ovajj has no wallet/gateway for your store currency (for example store **BDT**, Ovajj wallet **USD**).
WooCommerce → Settings → Payments → Ovajj → *Currency conversion*:

| Setting | Meaning |
|---|---|
| Convert currency | On/off. Off = exactly the 1.0 behaviour (order currency is sent to Ovajj). |
| Wallet currency | The currency of your Ovajj wallet/gateway, e.g. `USD`. The store currency is read from WooCommerce → General and shown on the page. |
| Exchange rate | **Fixed** (you type the rate) or **Live** (free `open.er-api.com`, no key, updated once a day). |
| Fixed rate | How many store-currency units equal **1 wallet unit**. Store BDT / wallet USD: enter `120` for 1 USD = 120 BDT. |
| Live rate safety margin % | Optional 0–20. Lowers the live rate so customers are charged slightly more USD, protecting you from rate swings. |

How it works:
* Only orders whose currency differs from the wallet currency are converted; USD orders pass through untouched.
* `wallet_amount = order_total / rate`, rounded to 2 decimals (filter `ovajj_wallet_decimals`). Ovajj is asked to charge the wallet amount in the wallet currency.
* The rate is **locked per payment attempt** and stored on the order (site amount, wallet amount, rate, source). A retry creates a new attempt with a fresh rate.
* Checkout shows the customer the estimate ("Your total of 12,000 BDT will be charged as about 100 USD (1 USD = 120 BDT)") using the cached rate (classic and Blocks).
* Webhook reconciliation checks the **charged** USD amount and currency against what was requested, and also that the BDT order total has not changed since the session was created. Verify-payment is checked against the charged amount too.
* Refunds entered in BDT are converted with the **locked rate**; refunding the entire remainder sends exactly the remaining USD, so rounding never leaves a cent behind. Refunds made in the Ovajj dashboard (USD) are mirrored into WooCommerce in BDT.
* Live mode: rates are cached 6 h (filter `ovajj_live_rate_ttl`). If a refresh fails, a cached rate up to 72 h old is used (filter `ovajj_live_rate_max_age`); if there is none, the payment is **not** started and the customer sees a retry message. A refreshed rate that jumps more than 50 % from the last one is rejected (filter `ovajj_max_rate_jump`). Fixed mode never calls the internet.
* The Live source requires attribution; the settings page shows the link "Rates By Exchange Rate API" as required by its terms. Its rates are daily reference rates, not bank buy/sell rates; use the safety margin or Fixed mode if you need to cover the spread.

Things to know: the customer's card/wallet statement may show the USD amount; your Ovajj fees are charged in USD; the plugin does not change WooCommerce prices or display two currencies in the cart. If Ovajj does support your store currency, leave conversion off.

## Webhook (IPN)
Endpoint: `https://YOUR-SITE/wc-api/ovajj_webhook` (shown on the settings page with the last-received status).
It is sent to Ovajj as `ipn_url` with every payment, so no manual registration is needed; if the dashboard asks for a
webhook URL, paste the same one. It must be publicly reachable (not `localhost`), ideally HTTPS.

Processing rules:
1. POST only; raw body read from `php://input`.
2. `X-Signature` = HMAC-SHA256(raw body, API Secret of the notification's environment), hex, optional `sha256=` prefix, compared with `hash_equals()`.
3. `X-Environment` / `data.environment` must agree and must equal the environment the payment attempt was created in.
4. `data.ref_trx` must be a reference this store issued for that order.
5. Success: currency and amount must equal both what was requested and the current order total. If a transaction ID is present, `GET /verify-payment/{trxId}` must also return `success` with matching currency/amount. Only then `payment_complete()` is called.
6. Duplicate deliveries (same body hash) are acknowledged without reprocessing; a per-order DB lock prevents races; paid orders are never downgraded.

Responses: 200 processed/duplicate/ignored · 202 pending · 400 malformed/environment mismatch · 401 bad signature · 404 unknown reference · 422 amount/currency mismatch (order goes on hold) · 503 busy or verification temporarily unavailable.

## Features
* Native WooCommerce gateway, classic checkout and Checkout Blocks, HPOS declared compatible.
* Hosted-checkout redirect; `payment_url` must be `https` on the API host (override list: `ovajj_allowed_checkout_hosts`).
* Signed requests exactly as documented (`timestamp.METHOD.path_with_query.raw_body`, `sha256=` prefix, same bytes signed and sent).
* Unique `ref_trx` per attempt (retries create a new session); amounts/currency taken from the order, never converted.
* Refunds from the WooCommerce order screen (full/partial) via `POST /refund-payment` with a deterministic `ref_refund` so a retried refund can't double-refund; refunds made in the Ovajj dashboard are mirrored into WooCommerce from the `payment.refunded` IPN.
* Background re-verification (up to 5 tries) plus an order action **Verify payment with Ovajj**.
* Sandbox badge at checkout; WooCommerce logs (source `ovajj-payment-gateway`) with secret/PII redaction.

## Known limitations and documentation gaps (important)
1. **The payment IPN example in the docs contains no Ovajj `trx_id`**, and `initiate-payment` does not return one. The plugin therefore treats a correctly signed, reconciled IPN as authoritative (the model the docs describe) and additionally calls verify-payment **whenever a `trx_id`/`transaction_id` field is present**. It never guesses a trx_id from `ref_trx`. Check a sandbox IPN: if no trx id appears, orders complete on the signed IPN alone, and **WooCommerce refunds are unavailable** (refund via the Ovajj dashboard; the IPN will be mirrored). Ask Ovajj to add `trx_id` to the IPN.
2. The verify example reports `amount` net of fee (237.5 + 12.5 fee = 250). The plugin accepts `amount` or `amount + fee` equal to the order total. The primary amount check uses the IPN `amount`, which equals the requested amount in the docs.
3. The docs list webhook events (failed, cancelled, pending, timeout) without status strings. The plugin recognises `completed`/`success`, `failed`/`cancelled`/`canceled`/`expired`/`timeout`/`timed_out`, `pending`, `refunded`; anything else is acknowledged and ignored.
4. The docs mention separate `test_webhook_secret`/`webhook_secret` in code samples but say "API secret" in prose. The plugin uses the API Secret (Client Secret) of the environment, as the task and the prose specify.
5. No API lists a merchant's supported currencies, so currency/wallet/gateway availability is enforced by Ovajj; the plugin shows a friendly message on `422` errors.
6. Ovajj refunds debit the merchant wallet; the original processing fee is not returned and returning money to a card is completed by Ovajj.
7. Conversion uses 2 decimals for the wallet currency by default (override with `ovajj_wallet_decimals` for zero-decimal currencies). Only one wallet currency is supported at a time.
8. Recurring payments/subscriptions endpoints are not implemented.
9. Minimum payment is 1.00 in the wallet currency (API rule).

## Manual test checklist (sandbox)
Demo data from the docs: wallet `123456789` PIN `123456`, voucher `TESTVOUCHER`; sandbox payments auto-succeed.

| # | Test | Expected |
|---|---|---|
| 1 | Activate/deactivate with WooCommerce active | No errors; Ovajj under Payments |
| 2 | Deactivate WooCommerce, keep plugin | Admin notice only, no fatal |
| 3 | Save wrong keys | Error on save "Could not authenticate"; checkout hides Ovajj if incomplete |
| 4 | Save production key in sandbox field (or `test_` key in production) | Rejected, not saved |
| 5 | Sandbox order, classic checkout | Redirect to Ovajj; after paying → order Processing, note "Ovajj payment completed (SANDBOX TEST)" |
| 6 | Same, Checkout Blocks page | Method listed with sandbox badge; same result |
| 7 | Cancel on the Ovajj page | Back to order-pay page with notice; order stays Pending; retry works with a new reference |
| 8 | Close tab after paying (no redirect) | IPN still completes the order |
| 9 | Replay the same IPN (e.g. from Ovajj/curl with valid signature) | 200 `duplicate`, no second completion |
| 10 | POST an IPN with a bad signature | 401, order unchanged |
| 11 | IPN with altered amount (re-signed in a test) | 422, order On hold |
| 12 | Wait on thank-you page before IPN | Message "waiting for Ovajj to confirm" |
| 13 | Refund partially then fully in the order screen (needs a trx id, see limitation 1) | Order notes show refund trx; WooCommerce shows the refunds; retry of the same refund does not duplicate |
| 14 | Refund in the Ovajj dashboard | IPN creates the WooCommerce refund once |
| 15 | HPOS on and off | Everything works; meta stored via the CRUD API |
| 16 | Currency without merchant wallet | Shopper sees "not available for your order currency"; note on order |
| 17 | WooCommerce → Status → Logs, source `ovajj-payment-gateway` | Events logged; no keys, secrets, signatures, emails or checkout tokens |
| 18 | Conversion on, Fixed 120, BDT order of 12,000 | Checkout text shows ~100 USD; Ovajj page shows 100.00 USD; after payment the order note shows "Charged 100 USD for an order of 12000 BDT (1 USD = 120 BDT, rate source: fixed)" |
| 19 | Live mode, save settings | Admin message "Live exchange rate: 1 USD = …"; "Current rate" row shows it with attribution link |
| 20 | Live mode with internet blocked and no cached rate | Customer sees "could not determine the exchange rate"; no Ovajj session is created |
| 21 | Change the order total in admin after the Ovajj session was created, then pay | IPN is rejected, order goes On hold |
| 22 | Refund 3,000 BDT of a 12,000 BDT order, then the remainder | Ovajj receives 25.00 USD then exactly 75.00 USD; WooCommerce shows both refunds |
| 23 | Order already in USD with conversion on | Sent unchanged in USD, no conversion note |

## Automated tests
`php tests/run-tests.php` — dependency-free, mocks all HTTP and the WordPress/WooCommerce functions it needs. Covers currency conversion (fixed/live/cache/stale/jump guard), converted reconciliation and refunds, signing,
header/body integrity, error mapping, log redaction, URL validation, signature verification, environment isolation, success/duplicate/
failed/pending IPNs, amount/currency mismatch, verification success/pending/failed/outage, cron retry and refund IPN idempotency.
Not covered by automation (use the manual checklist): the admin UI, Checkout Blocks rendering, `process_payment`/`process_refund`, and live Ovajj behavior.

## Troubleshooting
| Symptom | Cause / fix |
|---|---|
| 401 / `INVALID_CREDENTIALS` | Keys or secret belong to a different environment; re-enter all three for the selected one |
| 403 Merchant Pending Approval | Production needs admin approval; use sandbox |
| 422 Currency is not enabled / Receiver wallet not available | Enable the currency and activate the merchant wallet in Ovajj |
| Gateway missing at checkout | Not enabled, credentials incomplete for the selected environment, or order below 1.00 |
| "Rejected initiate-payment response" in logs | `payment_url` not https or not on the API host. If Ovajj uses another checkout domain, add it with the `ovajj_allowed_checkout_hosts` filter |
| Orders stay Pending | IPN not reaching the site (firewall/auth plugin/localhost). Check "Last notification" on the settings page and the logs |
| Webhook 401 | Secret mismatch or a proxy altering the body; the signature covers the exact raw body |
| 429 | Rate limit (120/min/merchant/IP); retry later |
| Timestamp rejected | Server clock off by more than ~300 s; fix NTP |
| "Could not determine the exchange rate" | Live mode and the rate service is unreachable with no recent cache, or Fixed mode with no rate saved. Save settings to refresh, or switch to Fixed |
| Ovajj still says currency not available after enabling conversion | The wallet currency you entered is not enabled on your Ovajj merchant account |
| Refund unavailable | No Ovajj trx id stored (limitation 1) or wallet balance insufficient |

## Developer hooks
`ovajj_api_base_url`, `ovajj_allowed_checkout_hosts`, `ovajj_enforce_key_prefixes`, `ovajj_wallet_decimals`, `ovajj_live_rate_ttl`, `ovajj_live_rate_max_age`, `ovajj_max_rate_jump`; cron hook `ovajj_reverify_order`.
