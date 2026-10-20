# Changelog

## 2.2.0
- Brought in line with the WHMCS module v2.2.0.
- **Processing Currency** dropdown: USD, EUR, GBP, USDT, BTC, ETH (default USD). Replaces "Wallet Currency".
- Any store/order currency can be converted: fiat rates for every currency the provider lists, crypto through Coinbase;
  crypto pairs resolved through USD.
- Settings page shows both rate sources (Exchange Rate API and Coinbase) with live feed links, the last known rate and
  the version.
- Currency-aware precision (8 decimals for BTC/ETH) and smallest-unit amount matching in webhook and refunds.
- Rate providers back off separately after failures; rates are cached for 1 hour with a 48-hour stale fallback.
- A "Wallet Currency" saved by 1.1 is migrated when it is one of the dropdown currencies.

## 1.1.0
- Optional currency conversion (Wallet Currency, Live/Fixed rate); per-session amount records; admin-only error reason.

## 1.0.0
- Initial release.
