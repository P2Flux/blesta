<?php
/**
 * php tests/bridge.php
 *
 * The gateway's pure rules: tokens, periods, amounts, labels, the subscribe rule.
 */
require __DIR__ . '/../p2flux/lib/P2fluxBridge.php';

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . "\n";
    $failures += $ok ? 0 : 1;
}

// Tokens: round trip, tamper, other key.
$t = P2fluxBridge::sign('k1', ['c' => 7, 'i' => [['id' => '12', 'amount' => '10.00']], 'a' => '10.00']);
check('token opens with its key', P2fluxBridge::open('k1', $t) === ['c' => 7, 'i' => [['id' => '12', 'amount' => '10.00']], 'a' => '10.00']);
check('token refused with another key', P2fluxBridge::open('k2', $t) === null);
[$body, $mac] = explode('.', $t);
$forged = rtrim(strtr(base64_encode(json_encode(['c' => 8, 'a' => '10.00'])), '+/', '-_'), '=');
check('changed body refused', P2fluxBridge::open('k1', $forged . '.' . $mac) === null);
check('malformed refused', P2fluxBridge::open('k1', 'abc') === null && P2fluxBridge::open('k1', '') === null);
check('token is URL safe', preg_match('/^[A-Za-z0-9_.-]+$/', $t) === 1);

// Periods.
check('1 month = 30 days', P2fluxBridge::periodSeconds(1, 'month') === 2592000);
check('3 months', P2fluxBridge::periodSeconds(3, 'month') === 7776000);
check('1 year = 365 days', P2fluxBridge::periodSeconds(1, 'year') === 31536000);
check('1 day is the minimum', P2fluxBridge::periodSeconds(1, 'day') === 86400);
check('onetime is not a subscription', P2fluxBridge::periodSeconds(1, 'onetime') === null);
check('term 0 refused', P2fluxBridge::periodSeconds(0, 'month') === null);

// Amounts, exactly.
check('units 10.5', P2fluxBridge::units('10.5') === 10500000);
check('units 10.000000', P2fluxBridge::units('10.000000') === 10000000);
check('units float', P2fluxBridge::units(9.99) === 9990000);
check('units refuses junk', P2fluxBridge::units('1e3') === null && P2fluxBridge::units('-1') === null && P2fluxBridge::units('1.1234567') === null);
check('decimal rounds to cents', P2fluxBridge::decimal(10.005) === '10.01' && P2fluxBridge::decimal(3) === '3.00');

// Subscribe rule.
$monthly = ['amount' => 10.0, 'term' => 1, 'period' => 'month'];
check('monthly 10 USD can subscribe', P2fluxBridge::canSubscribe(true, 10.0, $monthly));
check('not when the merchant turned it off', !P2fluxBridge::canSubscribe(false, 10.0, $monthly));
check('not when paying more than the recurring amount', !P2fluxBridge::canSubscribe(true, 25.0, $monthly));
check('not below 1 USDC', !P2fluxBridge::canSubscribe(true, 0.5, ['amount' => 0.5, 'term' => 1, 'period' => 'month']));
check('not one-time', !P2fluxBridge::canSubscribe(true, 10.0, ['amount' => 10.0, 'term' => 1, 'period' => 'onetime']));
check('not without recur', !P2fluxBridge::canSubscribe(true, 10.0, null));

// Labels.
check('invoice label kept', P2fluxBridge::label('Invoice #1234, #1235') === 'Invoice #1234, #1235');
check('label cut to 60', strlen((string) P2fluxBridge::label(str_repeat('a', 80))) === 60);
check('address-like label dropped', P2fluxBridge::label('Pay at evil.com') === null);
check('odd characters removed', P2fluxBridge::label('Invoice <b>#1</b>') === 'Invoice b#1/b');
check('empty label is null', P2fluxBridge::label('') === null && P2fluxBridge::label(null) === null);

// Invoices, wallets, ids.
check('invoices kept when well formed', P2fluxBridge::invoices([['id' => 5, 'amount' => 3.5], ['id' => 'x', 'amount' => 1], ['amount' => 1]]) === [['id' => '5', 'amount' => '3.50']]);
check('wallet ok', P2fluxBridge::validWallet('0x' . str_repeat('aB', 20)));
check('zero wallet refused', !P2fluxBridge::validWallet('0x' . str_repeat('0', 40)));
check('short wallet refused', !P2fluxBridge::validWallet('0x1234'));
check('id is 32 hex', preg_match('/^[0-9a-f]{32}$/', P2fluxBridge::newId()) === 1);
check('environments', P2fluxBridge::apiUrl('test') === 'https://api-test.p2flux.com' && P2fluxBridge::checkoutOrigin('live') === 'https://pay.p2flux.com');

// Review additions.
check('label mentioning p2flux dropped', P2fluxBridge::label('Pay P2Flux here') === null);
check('label with dot then letter dropped', P2fluxBridge::label('see v1.a') === null && P2fluxBridge::label('v1.2 plan') === 'v1.2 plan');
check('units of 0 is 0', P2fluxBridge::units('0') === 0);
check('zero-amount invoice line kept as 0.00', P2fluxBridge::invoices([['id' => 3, 'amount' => 0]]) === [['id' => '3', 'amount' => '0.00']]);
check('bad term refused', P2fluxBridge::periodSeconds('x', 'month') === null);
check('string recurring amount accepted', P2fluxBridge::canSubscribe(true, '10.00', ['amount' => '10.00', 'term' => '1', 'period' => 'month']));
check('recur without amount refused', !P2fluxBridge::canSubscribe(true, 10.0, ['term' => 1, 'period' => 'month']));
check('token with extra dot refused', P2fluxBridge::open('k1', $t . '.x') === null);
check('invalid UTF-8 description does not throw', is_string(P2fluxBridge::sign('k', ['d' => "\xff\xfe"])));
check('offer ignores expiry', P2fluxBridge::offer(['a' => '1', 'x' => 1]) === P2fluxBridge::offer(['x' => 2, 'a' => '1']));
check('offer differs by amount', P2fluxBridge::offer(['a' => '1']) !== P2fluxBridge::offer(['a' => '2']));

// merchant_ref: the invoice ids joined with ',', left out unless P2Flux accepts the result as is.
check('one invoice -> its id', P2fluxBridge::merchantRef([['id' => '101', 'amount' => '10.00']]) === '101');
check('no invoice -> none', P2fluxBridge::merchantRef([]) === null);
check('several invoices -> joined with a comma, which P2Flux does not accept: left out',
    P2fluxBridge::merchantRef([['id' => '101', 'amount' => '1.00'], ['id' => '102', 'amount' => '2.00']]) === null);
check('longer than 64 -> left out', P2fluxBridge::merchantRef([['id' => str_repeat('9', 65), 'amount' => '1.00']]) === null);

echo $failures === 0 ? "\nbridge: all checks passed\n" : "\nbridge: {$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
