<?php
/**
 * php tests/gateway.php
 *
 * The gateway on the stand-in Blesta, offline (no P2Flux calls): what it refuses, and that one
 * transaction is recorded once even when the browser and the webhook arrive together.
 */
putenv('HARNESS_DB=' . sys_get_temp_dir() . '/p2flux-blesta-gateway-test-' . getmypid() . '.json');
require __DIR__ . '/harness/gateway.php';

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . "\n";
    $failures += $ok ? 0 : 1;
}
function outcome(callable $f): string
{
    try {
        $r = $f();

        return 'returned:' . json_encode($r);
    } catch (HarnessRedirect $e) {
        return 'redirect:' . $e->getMessage();
    } catch (HarnessPage $e) {
        return 'page:' . $e->getMessage();
    }
}
function invoke(object $o, string $method, ...$args)
{
    $m = new ReflectionMethod($o, $method);
    $m->setAccessible(true);

    return $m->invoke($o, ...$args);
}

$wallet = '0x' . str_repeat('ab', 20);
$meta = ['recipient' => $wallet, 'environment' => 'test', 'gas_mode' => 'false', 'subscriptions' => 'true', 'callback_base' => ''];
$g = harness_gateway($meta);

// Settings.
$g->editSettings(['recipient' => '0x12', 'environment' => 'x', 'callback_base' => 'http://x/callback/gw']);
check('bad wallet, network and callback refused', array_keys($g->Input->errors() ?: []) === ['recipient', 'environment', 'callback_base']);
$g->Input->errors = [];

// Pay page.
$view = $g->buildProcess(['client_id' => 7], 10.0, [['id' => 101, 'amount' => 10.0]], ['description' => 'Invoice #101', 'recur' => ['amount' => 10.0, 'term' => 1, 'period' => 'month']]);
check('pay and subscribe offered', isset($view['pay_url'], $view['subscribe_url']));
check('no buttons without a client', !empty(harness_gateway($meta)->buildProcess([], 10.0, [], [])['unavailable']));
parse_str(parse_url($view['pay_url'], PHP_URL_QUERY), $pay);

// Start refusals: all shown in the popup, nothing created.
$tampered = $pay;
$tampered['t'] = substr($pay['t'], 0, -2) . 'xx';
check('tampered token -> stale page', outcome(fn () => harness_gateway($meta)->validate($tampered, [])) === 'page:P2flux.!error.start.stale');
$data = P2fluxBridge::open('p2flux|harness-system-key', $pay['t']);
$expired = ['a' => 'pay', 't' => P2fluxBridge::sign('p2flux|harness-system-key', ['x' => time() - 1] + $data)];
check('expired token -> stale page', outcome(fn () => harness_gateway($meta)->validate($expired, [])) === 'page:P2flux.!error.start.stale');
check('network switched since render -> stale page', outcome(fn () => harness_gateway(['environment' => 'live'] + $meta)->validate($pay, [])) === 'page:P2flux.!error.start.stale');
check('wallet changed since render -> stale page', outcome(fn () => harness_gateway(['recipient' => '0x' . str_repeat('cd', 20)] + $meta)->validate($pay, [])) === 'page:P2flux.!error.start.stale');

// A paid offer is not paid twice: a record for it with a transaction -> "already paid".
$offer = hash('sha256', 'pay|' . P2fluxBridge::offer($data));
$id = P2fluxBridge::newId();
$reference = '0x' . str_repeat('12', 32);
invoke($g, 'newRecord', $id, 'pay', $offer, $data, ['intent' => 'p2f1.test', 'reference' => $reference, 'expires' => time() + 3600]);
$record = (object) HarnessRecord::instance()->select()->from('p2flux_records')->where('id', '=', $id)->fetch();
$tx = '0x' . str_repeat('34', 32);

// The browser and the webhook confirm the same transaction: recorded once.
$first = invoke(harness_gateway($meta), 'paid', $record, $tx, '10.000000');
$second = invoke(harness_gateway($meta), 'paid', $record, $tx, '10.000000');
check('first confirmation recorded, applied to the invoice', ($first['status'] ?? '') === 'approved' && ($first['invoices'][0]['id'] ?? '') === '101');
check('second confirmation of the same tx answers nothing', $second === null);
check('then the offer shows "already paid"', outcome(fn () => harness_gateway($meta)->validate($pay, [])) === 'page:P2flux.paid_already');
check('success page only once recorded', (harness_gateway($meta)->success(['r' => $reference], [])['transaction_id'] ?? '') === $tx);

// A different amount is credit, never applied; a malformed answer is refused.
$id2 = P2fluxBridge::newId();
invoke($g, 'newRecord', $id2, 'pay', 'h2', $data, ['intent' => 'p2f1.x', 'reference' => '0x' . str_repeat('56', 32), 'expires' => time() + 3600]);
$record2 = (object) HarnessRecord::instance()->select()->from('p2flux_records')->where('id', '=', $id2)->fetch();
$credit = invoke(harness_gateway($meta), 'paid', $record2, '0x' . str_repeat('78', 32), '9.990000');
check('short payment -> credit with no invoices', is_array($credit) && $credit['invoices'] === null && $credit['amount'] === '9.99');
check('malformed tx refused', invoke(harness_gateway($meta), 'paid', $record2, '0x12', '10') === null);
check('nothing recorded while confirming', harness_gateway($meta)->success(['k' => $id2], []) !== null && harness_gateway($meta)->success(['k' => P2fluxBridge::newId()], []) === null);

// Webhooks: a subscription event posted to a payment record is ignored, never a 500.
$sub = json_encode(['id' => '0x1', 'type' => 'subscription.charged', 'data' => ['link_id' => '0x' . str_repeat('9', 32)]]);
check('subscription event on a payment record -> nothing', invoke(harness_gateway($meta), 'confirmHook', $record, $sub) === null);
// A record from the other network is ignored before any API call.
check('record from the other network ignored', outcome(fn () => harness_gateway(['environment' => 'live'] + $meta)->validate(['a' => 'hook', 'k' => $id], [])) === 'returned:null');

@unlink(getenv('HARNESS_DB'));
@unlink(getenv('HARNESS_DB') . '.lock');
echo $failures === 0 ? "\ngateway: all checks passed\n" : "\ngateway: {$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
