# Changelog

## 1.1.0 - unreleased

- A payment sends its invoice id(s) as `merchant_ref` (several invoices joined with `/`, e.g. `101/102`;
  left out when longer than 64 characters). Against an API that does not know `merchant_ref` yet (it
  answers `400 INVALID_REQUEST` without naming the field), the payment is asked for once more without it.
- Signed webhooks: a `p2flux-signature` header is checked with the SDK (keys from the API's
  capabilities, five-minute window) before the API is asked; a bad, stale or unknown-key signature is
  refused. Webhooks without the header are confirmed with the API as before. Without PHP's sodium
  extension the signature is not checked (logged) and the API check still applies.
- Vendored SDK: sdk-php 0.15.0 (adds `Keccak.php`).

## 1.0.1 - 2026-10-22

- A renewal P2Flux learned from the chain (no transaction in the event) is credited, once.

## 1.0.0 - 2026-10-19

First release.
