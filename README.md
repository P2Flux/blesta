# P2Flux for Blesta

Accept USDC on Base in [Blesta](https://www.blesta.com): one-time invoice payments and automatic
payments for recurring services. Non-custodial: every payment goes straight from the client's wallet
to yours, inside the payment transaction. P2Flux never holds the money, and there is no account or
API key to set up.

## Install

1. Download `p2flux-blesta-<version>.zip` from the [releases](https://github.com/P2Flux/blesta/releases)
   and unpack it into `components/gateways/nonmerchant/` (you get `components/gateways/nonmerchant/p2flux/`).
2. In Blesta: **Settings > Company > Payment Gateways > Available**, install **P2Flux (USDC)**.
3. Enter your Base wallet address, choose the network, save.

Requirements: Blesta 6 (PHP 8.1+ with curl), invoices in **USD** (USDC is a US-dollar stablecoin),
and a Blesta address that P2Flux can reach over **https** for confirmations.

## Settings

| Setting | What it does |
|---|---|
| Your wallet address | The Base wallet that receives every payment. |
| Network | Base Mainnet (real USDC) or Base Sepolia (test, no real money). Each payment keeps the network it was started on. |
| Buyers can pay without ETH | Clients pay the network cost in USDC; a fixed 0.10 USDC per payment comes out of your amount. |
| Offer automatic payment for recurring services | Shows "Pay automatically every …" on invoices of recurring services. |
| Public callback base URL | Behind a proxy or tunnel, or when the server answers more than one hostname: the public `https://…/callback/gw` address P2Flux sends its confirmations to. Otherwise the company's hostname is used. The client's own browser always uses the address Blesta is opened at. |

## How it works

**One-time payment.** The client clicks **Pay with USDC**; P2Flux's checkout opens in a small window
and the client pays from their own wallet (MetaMask, Rabby, or a phone wallet via QR). Blesta records
the payment as soon as the P2Flux API confirms it on chain, either through the client's browser or
through P2Flux's notification to the gateway callback (retried for about 18 hours), whichever comes
first. The transaction id is the chain transaction hash, so a repeated notification never creates a
second payment. A payment of a different amount than the invoice is recorded as credit, not applied.

**Automatic payment.** For a recurring service, the client can choose **Pay automatically every …**.
They approve once in their wallet; P2Flux charges the first period immediately (applied to the
invoice) and then collects every period by itself. Each later charge is recorded in Blesta as a
payment to the client's account and applied to the open renewal invoice by Blesta's
**Auto Apply Credits** (on by default). P2Flux bills on fixed intervals: a month is 30 days, a year
365 days. The client can stop at any time from the P2Flux payment page; if you cancel the service in
Blesta, the next charge that still arrives is kept as credit and collection is stopped.

**Switching the network or the wallet** in the settings invalidates every payment page started
before; pay pages are valid for a day. A test payment is never recorded once the gateway is on
Mainnet. (Real payments made while the gateway was temporarily switched to test mode are still
recorded: each payment is checked on the network it was made on.)

**One subscription per link.** Each automatic payment uses its own P2Flux link. The first wallet that
subscribes through it is the client's; anyone else who subscribes through the same link is stopped and
never credited. A client who stops and later subscribes again gets a new link of their own.
If P2Flux cannot reach Blesta for longer than its retry window (about 18 hours) and a newer period is
collected meanwhile, the older period is not recorded automatically: the gateway log shows it as
`NOT_CURRENT`, and the P2Flux overview lists every charge. A cancelled service is recognised from the
invoice's service lines; an invoice without a service line keeps collecting until the client stops it.
Uninstalling the gateway stops all active subscriptions first. Its records (which wallet payment
belongs to which invoice) are kept unless it is the last P2Flux gateway installed; unpaid payment
records are removed a month after they expired.

**Troubleshooting.** Every confirmation is in Blesta's gateway log (Tools > Logs > Gateway). If a
payment shows there as confirmed but no transaction appears in Blesta (Blesta refused it, for example
because the client was deleted), add the transaction by hand with the transaction hash from the log.
A warning "no webhook for this payment" means P2Flux could not watch that payment: it is then recorded
when the client's browser returns.

**Refunds.** Blesta cannot send a P2Flux refund: a refund is a transfer from your own wallet. Use the
P2Flux refund page, or for automatic payments send it to an address the client gives you.

## Development

```
dev/vendor-sdk.sh ../p2flux_sdk_php v0.12.0   # vendor the PHP SDK (namespace rewritten)
php tests/bridge.php                           # pure rules
php tests/gateway.php                          # the gateway on a stand-in for Blesta, offline
php tests/flows.php                            # whole flows against a scripted P2Flux API
dev/docker-compose.yml                         # local Blesta 6 (needs the Blesta zip and a licence)
tests/harness/                                 # a stand-in for Blesta's classes, to run the gateway
                                               # against the P2Flux test API without Blesta
dev/package.sh 1.0.0                           # release zip
```

Docs: https://p2flux.com/docs/blesta.html · Licence: MIT
