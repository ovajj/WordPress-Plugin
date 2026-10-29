# Ovajj Payment Gateway for WooCommerce

Official WooCommerce payment gateway for Ovajj hosted checkout (<https://ovajj.com/api-docs>), not affiliated with
or endorsed by Ovajj. Requires **WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+**. Works with the block checkout, the
classic checkout and HPOS (custom order tables).

> **Status:** built from the public API docs; there are no automated tests and it has not been independently audited
> or run against an Ovajj sandbox by the author. Run the sandbox test plan below before any live use.

## Install

1. Upload the `ovajj` folder to `wp-content/plugins/` (or Plugins → Add New → Upload the zip).
2. Activate it.
3. WooCommerce → Settings → Payments → **Ovajj Payment Gateway** → Manage.

## Settings

| Field | Meaning |
|---|---|
| Enable | Turns the gateway on. |
| Environment | Dropdown: **Sandbox** or **Live**. |
| Merchant Key / API Key / API Secret | One set; must belong to the selected environment. Replace all three when you switch. |
| Processing Currency | Dropdown: USD, EUR, GBP, USDT, BTC, ETH (default USD). The currency Ovajj is charged in; orders in any other currency are converted to it. |
| Exchange Rate | **Live** (free, automatic, cached 1 hour) or **Fixed**. |
| Fixed Rate | Fixed mode only, for orders in the store (base) currency: how many store-currency units equal 1 Processing-Currency unit (processing USD, store BDT, `122.50` = 1 USD = 122.50 BDT). Other order currencies always use the live rate. |
| Debug Logging | Extra diagnostics in WooCommerce → Status → Logs (source `ovajj`). Credentials are never logged. |

The settings page shows both rate sources with their live feeds, the last known rate, and the plugin version.
No webhook URL needs to be entered: every payment request carries a per-order `ipn_url`
(`https://yoursite/wc-api/ovajj/?order=ID&t=TOKEN`). Your site must be publicly reachable; Live mode requires HTTPS.

## Currency conversion

The store currency can be anything (any country's currency, or BTC, ETH, USDT). If it differs from the Processing
Currency, the order total is converted at checkout; the **WooCommerce order stays in the store currency**.

- Amount sent to Ovajj = `order total / rate`, rounded to 2 decimals (8 for BTC and ETH).
- Customers are told at checkout which currency they are charged in.
- **Live** rates come from two free public sources, linked on the settings page:
  - **Fiat (every country's currency):** [Rates By Exchange Rate API](https://www.exchangerate-api.com), live feed
    <https://open.er-api.com/v6/latest/USD> (no key, updated daily by the provider, attribution required).
  - **BTC, ETH, USDT:** [Coinbase](https://www.coinbase.com/price) public spot price, live feed
    <https://api.coinbase.com/v2/prices/BTC-USD/spot> (no key). Pairs with a crypto side are resolved through USD.
  - Rates are cached for 1 hour; if a provider is unreachable the last known rate is used for up to 48 hours, after
    which payments are refused instead of guessing. A currency the fiat provider does not list is also refused.
- Each payment session records its amount, currency and rate on the order. The webhook must match that session
  (currency, amount, ±1 smallest unit because Ovajj rounds fee and net separately), so a rate change between sessions
  cannot cause a mismatch.
- Refunds are made in the Processing Currency at the rate the customer paid; a full refund returns exactly what was charged.
- Rate movement and Ovajj fees are your business risk; use a Fixed rate with your margin if you need protection.
- Upgrading from plugin 1.1: a saved "Wallet Currency" of USD, EUR or GBP is kept as the Processing Currency; BDT or
  "same as store" becomes USD (re-check the setting after upgrading).

### Currencies and the Ovajj API

Checked against the Ovajj API documentation:

- No list of supported currencies is published. `currency_code` must be a "3-letter" code enabled for the merchant;
  examples are USD, EUR and BDT. GBP, BTC, ETH and USDT are not mentioned.
- Each currency needs an **active merchant wallet** (otherwise `422 Receiver wallet for this currency is not
  available`) and a gateway configured for **exactly that currency** ("No cross-currency gateways").
- `payment_amount` has a **minimum of 1.00**. For BTC and ETH that means 1 BTC / 1 ETH, so almost every order is refused
  as "below the Ovajj minimum" (the reason is shown to shop managers at checkout and logged). USDT has four letters and
  may be rejected as an invalid code. Confirm these with Ovajj before using crypto; USD is the safe choice, and
  EUR/GBP work only if your Ovajj account has them.

## Flow

1. Customer chooses **Ovajj Payment Gateway** and places the order.
2. The plugin creates the session (`POST /api/v1/initiate-payment`) and redirects to Ovajj.
3. Ovajj sends a signed IPN. The plugin verifies signature, order token and environment, calls
   `GET /api/v1/verify-payment/{trx_id}`, checks currency and amount against the matching session, and only then calls
   `payment_complete()`. The thank-you page never completes an order by itself.
4. Cancel on Ovajj returns the customer to the order's pay page so they can retry.
5. Refunds: WooCommerce → order → Refund → "Refund via Ovajj Payment Gateway".

| Ovajj | Result |
|---|---|
| completed + verify `success` | Order paid, transaction ID = Ovajj `trx_id` |
| pending | Logged; verify-pending answers 503 so Ovajj can retry |
| failed / cancelled / expired | Pending order → Failed (retryable); otherwise a note only |
| refunded (IPN) | Order note only |

## Security checklist

- [x] Signed API requests (HMAC-SHA256), TLS verification on, no redirects, 30 s timeout
- [x] Webhook signature checked (constant-time) on the raw body before parsing
- [x] Independent `verify-payment` before completing
- [x] Per-order HMAC token on `ipn_url` + HMAC-signed `ref_trx`
- [x] Sandbox ↔ Live isolation (header, body `environment`, `is_sandbox`)
- [x] Currency and amount checked against the recorded payment session
- [x] Idempotent: transaction-ID lookup + MySQL advisory lock per order
- [x] Replay window (1 h) on webhook `timestamp`
- [x] Already-paid/cancelled/refunded orders never receive a second payment
- [x] Secrets never logged or sent to the browser; customers see generic errors (shop managers also see the reason)

## Sandbox test plan

| # | Test | Expected |
|---|---|---|
| 1 | Pay an order in the Processing Currency | Paid; transaction ID set; note "verified and recorded" |
| 2 | Pay an order in another currency | Converted; note shows amount, currency and rate; paid |
| 3 | Cancel at Ovajj | Back on pay page; order stays Pending |
| 4 | Wrong API key/secret | Generic checkout error (reason for shop managers); log entry |
| 5 | Webhook with modified body | 401, log "signature verification failed" |
| 6 | Re-send a valid webhook / two at once | One payment only |
| 7 | Wrong `t=` token | 403 |
| 8 | Sandbox webhook while set to Live | 400 `environment_mismatch` |
| 9 | Block outbound HTTPS to ovajj.com | 503 on webhook; order untouched |
| 10 | Block the rate providers | Last known rate used; after 48 h payment refused with a clear reason |
| 11 | Full and partial refund of a converted order | Success in the Processing Currency; repeat within the hour not double-refunded |

## Troubleshooting

| Symptom | Check |
|---|---|
| Ovajj not shown at checkout | Gateway enabled? All three credentials filled? |
| "Payment could not be started" | Log in as a shop manager: the reason follows `[Admin only]`. Also order note and WooCommerce → Status → Logs (`ovajj`) |
| `Currency is not enabled…` / `Receiver wallet…` | The Processing Currency is not enabled for your Ovajj merchant (see above) |
| `below the Ovajj minimum of 1.00` | Converted amount is under 1.00 (typical for BTC/ETH) |
| `no usable exchange rate` | Rate providers unreachable or currency not listed |
| Paid at Ovajj, order still Pending | Logs: *signature failed* (wrong Secret/environment), *missing trx_id*, or no entry (site unreachable: firewall, HTTP-only, caching of `/wc-api/`) |

## Known limitations (confirm with Ovajj)

1. **`trx_id` in the webhook.** `verify-payment` needs Ovajj's transaction ID; the documented webhook example only shows
   `data.ref_trx`. The plugin requires `data.trx_id` and otherwise refuses to complete the order.
2. Webhooks are verified with the API Secret. If Ovajj uses a separate webhook secret, a small change is needed.
3. `verify-payment` `amount` may be net of fee; accepted as `amount` or `amount + fee` within ±1 smallest unit.
4. Refunds reverse the Ovajj ledger; the original processing fee is not returned. Identical partial refunds within one
   clock hour are collapsed by design.
5. Minimum amount 1.00 in the Processing Currency. Subscriptions/recurring endpoints are not used.

## License

MIT.
