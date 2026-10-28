# Changelog

## 1.1.0 - unreleased

- A payment sends its invoice number(s) as bookkeepers see them (Blesta's id code, e.g. `INV-101`; the id
  when there is none) as `merchant_ref` (several invoices joined with `/`, e.g. `INV-101/INV-102`; left out
  when longer than 64 characters). Against an API that does not know `merchant_ref` yet (it answers
  `400 INVALID_REQUEST` without naming the field), the payment is asked for once more without it, and that
  environment is remembered for an hour (a file in the system temp dir) so later payments go once.
- Signed webhooks: a `p2flux-signature` header is checked with the SDK (keys from the API's
  capabilities, five-minute window) before the API is asked; a bad or unknown-key signature is answered
  `401` (retried, visible in the delivery history); a stale one (clock skew) is confirmed with the API. Webhooks without the header are confirmed with the API as before. Without PHP's sodium
  extension the signature is not checked (logged) and the API check still applies.
- Vendored SDK: sdk-php 0.15.0 (adds `Keccak.php`).

## 1.0.1 - 2026-10-22

- A renewal P2Flux learned from the chain (no transaction in the event) is credited, once.

## 1.0.0 - 2026-10-19

First release.
