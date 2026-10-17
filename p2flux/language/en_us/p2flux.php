<?php
// Gateway
$lang['P2flux.name'] = 'P2Flux (USDC)';
$lang['P2flux.description'] = 'USDC payments and subscriptions on Base, paid straight to your wallet. P2Flux never holds the money.';

// Settings
$lang['P2flux.recipient'] = 'Your wallet address';
$lang['P2flux.recipient_note'] = 'The Base wallet that receives every payment (0x followed by 40 characters). Payments go straight to it.';
$lang['P2flux.environment'] = 'Network';
$lang['P2flux.environment.live'] = 'Base Mainnet (real USDC)';
$lang['P2flux.environment.test'] = 'Base Sepolia (test, no real money)';
$lang['P2flux.gas_mode'] = 'Buyers can pay without ETH';
$lang['P2flux.gas_mode_note'] = 'Buyers pay the network cost in USDC; a fixed 0.10 USDC per payment comes out of your amount. One-time payments only.';
$lang['P2flux.subscriptions'] = 'Offer automatic payment for recurring services';
$lang['P2flux.subscriptions_note'] = 'The client approves once in their wallet; P2Flux then collects each period and Blesta records it as a payment, applied to the renewal invoice.';
$lang['P2flux.callback_base'] = 'Public callback base URL (optional)';
$lang['P2flux.callback_base_note'] = 'Only if Blesta is behind a proxy or tunnel: the public https address that ends in /callback/gw. Leave empty otherwise.';
$lang['P2flux.callback_url'] = 'Callback address (P2Flux sends confirmations here; it must be reachable over https)';
$lang['P2flux.test_mode'] = 'Test mode: payments use Base Sepolia and move no real money.';
$lang['P2flux.refunds'] = 'Refunds are sent from your own wallet on the P2Flux refund page; Blesta cannot send them for you. Charges collected by a subscription are refunded by hand to an address the client gives you.';

$lang['P2flux.!error.recipient.valid'] = 'Enter the Base wallet address that receives payments (0x followed by 40 characters).';
$lang['P2flux.!error.environment.valid'] = 'Choose Base Mainnet or Base Sepolia.';
$lang['P2flux.!error.callback_base.valid'] = 'The callback base must be an https address ending in /callback/gw, or empty.';
$lang['P2flux.!error.start.stale'] = 'This payment page is out of date. Close this window, reload the invoice and pay from there.';
$lang['P2flux.paid_already'] = 'This invoice has already been paid with USDC. You can close this window.';
$lang['P2flux.unavailable'] = 'USDC payment is not available for this invoice. Please choose another payment method or contact us.';
$lang['P2flux.!error.start.config'] = 'This payment cannot be created. Please contact us.';
$lang['P2flux.!error.start.unavailable'] = 'The payment service is unreachable right now. Please try again in a minute.';

// Pay page
$lang['P2flux.pay'] = 'Pay with USDC';
$lang['P2flux.subscribe'] = 'Pay automatically %1$s with USDC';
$lang['P2flux.subscribe_note'] = 'You approve once in your wallet; each payment then goes to us automatically. You can stop it at any time from the payment page.';
$lang['P2flux.waiting'] = 'Complete the payment in the P2Flux window…';
$lang['P2flux.confirming'] = 'Your payment has been sent and is being confirmed. Do not pay again.';
$lang['P2flux.popup_blocked'] = 'Allow pop-ups for this page, or use the button again.';
$lang['P2flux.test_notice'] = 'Test mode: no real money moves.';

$lang['P2flux.every.day'] = 'every day';
$lang['P2flux.every.days'] = 'every %1$s days';
$lang['P2flux.every.week'] = 'every week';
$lang['P2flux.every.weeks'] = 'every %1$s weeks';
$lang['P2flux.every.month'] = 'every month';
$lang['P2flux.every.months'] = 'every %1$s months';
$lang['P2flux.every.year'] = 'every year';
$lang['P2flux.every.years'] = 'every %1$s years';
