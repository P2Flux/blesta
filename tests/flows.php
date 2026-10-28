<?php
/**
 * php tests/flows.php
 *
 * The gateway's flows end to end on the stand-in Blesta, against a scripted P2Flux API (no network):
 * start -> record -> checkout, second click, payment confirmed, subscription signup, renewal, a
 * stranger on the link, the client subscribing again, a cancelled service, network guard, uninstall.
 */
putenv('HARNESS_DB=' . sys_get_temp_dir() . '/p2flux-blesta-flows-' . getmypid() . '.json');
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
        return 'returned:' . json_encode($f());
    } catch (HarnessRedirect $e) {
        return 'redirect:' . $e->getMessage();
    } catch (HarnessPage $e) {
        return 'page:' . $e->getMessage();
    }
}
function rows(): array
{
    return json_decode((string) @file_get_contents(getenv('HARNESS_DB')), true)['p2flux_records'] ?? [];
}

// --- scripted API -----------------------------------------------------------------------------
$api = ['calls' => [], 'subscribers' => [], 'stopped' => [], 'payments' => 0, 'links' => 0];
$hex = static fn (string $seed, int $bytes) => '0x' . substr(hash('sha256', $seed), 0, $bytes * 2);
$GLOBALS['harness_api'] = static function (string $path, array $payload) use (&$api, $hex) {
    $api['calls'][] = $path;
    $api['last'][$path] = $payload;
    switch ($path) {
        case '/v1/capabilities':
            return [200, ['webhook_signing_keys' => $api['keys'] ?? []]];
        case '/v1/payments':
            if (!empty($api['rate'])) {
                return [429, ['error' => 'RATE_LIMITED']];
            }
            if (!empty($api['old']) && isset($payload['merchant_ref'])) {
                // An API older than merchant_ref: an unknown field is a 400 that names nothing.
                return [400, ['error' => 'INVALID_REQUEST', 'action' => 'FIX_REQUEST']];
            }
            $n = ++$api['payments'];

            return [200, ['intent' => "p2f1.intent$n", 'reference' => $hex("ref$n", 32), 'amount' => $payload['amount'], 'expires_at' => time() + 3600,
                'notify_url' => $payload['notify_url'], 'pay' => []]];
        case '/v1/links':
            $n = ++$api['links'];

            return [200, ['link' => "p2l1.link$n", 'manage' => "p2lm1.manage$n", 'id' => $hex("link$n", 16), 'kind' => 'subscription', 'expires_at' => time() + 86400 * 365]];
        case '/v1/links/status':
            $n = (int) substr($payload['manage'], strlen('p2lm1.manage'));

            return [200, ['id' => $hex("link$n", 16), 'subscribers' => array_values($api['subscribers'][$n] ?? [])]];
        case '/v1/links/stop':
            $api['stopped'][] = $payload['subscription_id'];

            return [200, ['subscription_id' => $payload['subscription_id'], 'state' => 'stopped']];
    }

    return [404, ['error' => 'NOT_FOUND']];
};
/** A subscription event as P2Flux would send it, with the link's status set to match. */
$charge = static function (int $link, string $sub, int $period, string $tag, string $type = 'subscription.charged') use (&$api, $hex) {
    $api['subscribers'][$link][$sub] = ['subscription_id' => $sub, 'state' => 'active', 'last_period' => $period, 'tag' => $tag,
        'last_tx' => $hex("tx-$sub-$period", 32), 'paid_count' => $period + 1];

    return json_encode(['id' => '0x1', 'type' => $type, 'data' => ['link_id' => $hex("link$link", 16), 'subscription_id' => $sub, 'period_index' => $period]]);
};
$hook = static function (array $meta, string $record, string $body) {
    $g = harness_gateway($meta);
    $m = new ReflectionMethod($g, 'confirmHook');
    $m->setAccessible(true);
    $row = (object) current(array_filter(rows(), static fn ($r) => $r['id'] === $record));

    return $m->invoke($g, $row, $body);
};

$meta = ['recipient' => '0x' . str_repeat('ab', 20), 'environment' => 'test', 'gas_mode' => 'false', 'subscriptions' => 'true', 'callback_base' => 'https://pub.example.com/callback/gw'];
$view = harness_gateway($meta)->buildProcess(['client_id' => 7], 10.0, [['id' => 101, 'amount' => 10.0]], [
    'description' => 'Invoice #101', 'recur' => ['amount' => 10.0, 'term' => 1, 'period' => 'month'],
]);
parse_str(parse_url($view['pay_url'], PHP_URL_QUERY), $pay);
parse_str(parse_url($view['subscribe_url'], PHP_URL_QUERY), $sub);

// --- one-time payment -------------------------------------------------------------------------
$first = outcome(fn () => harness_gateway($meta)->validate($pay, []));
check('start -> checkout for the minted intent', $first === 'redirect:https://pay-test.p2flux.com/#/pay/p2f1.intent1');
check('the webhook address is the public base, not the request host', str_starts_with($api['last']['/v1/payments']['notify_url'] ?? '', 'https://pub.example.com/callback/gw/1/p2flux/?a=hook&k='));
check('second click reuses the same payment', outcome(fn () => harness_gateway($meta)->validate($pay, [])) === $first && $api['payments'] === 1);
check('the payment carries the invoice id as merchant_ref', ($api['last']['/v1/payments']['merchant_ref'] ?? null) === '101');

// Two invoices at once: "101,102" is not a merchant_ref P2Flux accepts, so none is sent.
$multi = harness_gateway($meta)->buildProcess(['client_id' => 7], 30.0, [['id' => 101, 'amount' => 10.0], ['id' => 102, 'amount' => 20.0]], ['description' => 'Invoices']);
parse_str(parse_url($multi['pay_url'], PHP_URL_QUERY), $payMulti);
outcome(fn () => harness_gateway($meta)->validate($payMulti, []));
check('two invoices: no merchant_ref', $api['payments'] === 2 && !array_key_exists('merchant_ref', $api['last']['/v1/payments']));

// An API that does not know merchant_ref yet: the payment is still created, once more without it.
$api['old'] = true;
$older = harness_gateway($meta)->buildProcess(['client_id' => 7], 5.0, [['id' => 103, 'amount' => 5.0]], ['description' => 'Invoice #103']);
parse_str(parse_url($older['pay_url'], PHP_URL_QUERY), $payOlder);
$before = count(array_keys($api['calls'], '/v1/payments', true));
$res = outcome(fn () => harness_gateway($meta)->validate($payOlder, []));
check('old API: checkout opens for the payment created without merchant_ref', $res === 'redirect:https://pay-test.p2flux.com/#/pay/p2f1.intent3'
    && count(array_keys($api['calls'], '/v1/payments', true)) - $before === 2 && !array_key_exists('merchant_ref', $api['last']['/v1/payments']));
$api['old'] = false;
$broken = harness_gateway($meta)->buildProcess(['client_id' => 7], 6.0, [['id' => 104, 'amount' => 6.0]], ['description' => 'Invoice #104']);
parse_str(parse_url($broken['pay_url'], PHP_URL_QUERY), $payBroken);
$before = count(array_keys($api['calls'], '/v1/payments', true));
$api['rate'] = true;
$res = outcome(fn () => harness_gateway($meta)->validate($payBroken, []));
check('any other refusal is not resent', $res === 'page:P2flux.!error.start.unavailable' && count(array_keys($api['calls'], '/v1/payments', true)) - $before === 1);
$api['rate'] = false;
check('browser links use the address Blesta is opened at', str_starts_with($view['pay_url'], 'https://billing.example.com/callback/gw/1/p2flux/'));

// --- subscription -----------------------------------------------------------------------------
putenv('HARNESS_MODELS={"Invoices":{"101":{"line_items":[{"service_id":55}]}},"Services":{"55":{"status":"active"}}}');
$started = outcome(fn () => harness_gateway($meta)->validate($sub, []));
$subRow = current(array_filter(rows(), static fn ($r) => $r['kind'] === 'subscribe'));
check('subscribe -> tagged link checkout', $started === 'redirect:https://pay-test.p2flux.com/#/link/p2l1.link1~0x' . $subRow['id']);
check('the record knows the services behind the invoice', $subRow['service_ids'] === '[55]');
$tag = '0x' . $subRow['id'];
$owner = $hex('owner', 32);
$signup = $hook($meta, $subRow['id'], $charge(1, $owner, 0, $tag));
check('signup charge pays the invoice', ($signup['invoices'][0]['id'] ?? '') === '101' && $signup['status'] === 'approved');
$renewal = $hook($meta, $subRow['id'], $charge(1, $owner, 1, $tag));
check('renewal is credit', is_array($renewal) && $renewal['invoices'] === null && $renewal['transaction_id'] !== $signup['transaction_id']);
check('the same renewal again answers nothing', $hook($meta, $subRow['id'], $charge(1, $owner, 1, $tag)) === null);
// A period P2Flux learned from the chain comes without a transaction: still credited, and only once.
$charge(1, $owner, 2, $tag);
$api['subscribers'][1][$owner]['last_tx'] = null;
$body2 = json_encode(['id' => '0x2', 'type' => 'subscription.charged', 'data' => ['link_id' => $hex('link1', 16), 'subscription_id' => $owner, 'period_index' => 2]]);
$learned = $hook($meta, $subRow['id'], $body2);
check('a renewal without a transaction is still credited', is_array($learned) && $learned['invoices'] === null && $learned['transaction_id'] === $owner . ':2');
check('and only once', $hook($meta, $subRow['id'], $body2) === null);
$next = $hook($meta, $subRow['id'], $charge(1, $owner, 3, $tag));
check('the next renewal with its transaction is credited again', is_array($next) && $next['transaction_id'] !== $learned['transaction_id']);
$stranger = $hex('stranger', 32);
check('a stranger on the same link is not credited', $hook($meta, $subRow['id'], $charge(1, $stranger, 0, $tag)) === null);
check('...and is stopped', in_array($stranger, $api['stopped'], true));

// Signed webhooks: checked when the header is there (keys from capabilities), and the API is still asked.
$pair = sodium_crypto_sign_keypair();
$b64 = static fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$api['keys'] = [['kid' => 'k1', 'alg' => 'Ed25519', 'public_key' => $b64(sodium_crypto_sign_publickey($pair))]];
$sign = static fn (string $body, int $t, string $kid = 'k1') => "t=$t,kid=$kid,v1=" . $b64(sodium_crypto_sign_detached("$t.$body", sodium_crypto_sign_secretkey($pair)));
$signed = $charge(1, $owner, 4, $tag);
$_SERVER['HTTP_P2FLUX_SIGNATURE'] = $sign($signed . ' ', time());
$statusCalls = count(array_keys($api['calls'], '/v1/links/status', true));
check('a bad signature is refused', $hook($meta, $subRow['id'], $signed) === null);
check('...before the API is asked', count(array_keys($api['calls'], '/v1/links/status', true)) === $statusCalls);
$_SERVER['HTTP_P2FLUX_SIGNATURE'] = $sign($signed, time() - 600);
check('a signature older than five minutes is refused', $hook($meta, $subRow['id'], $signed) === null);
$_SERVER['HTTP_P2FLUX_SIGNATURE'] = $sign($signed, time(), 'k2');
check('an unknown key is refused', $hook($meta, $subRow['id'], $signed) === null);
$_SERVER['HTTP_P2FLUX_SIGNATURE'] = $sign($signed, time());
$good = $hook($meta, $subRow['id'], $signed);
check('a good signature is credited', is_array($good) && $good['transaction_id'] === $hex("tx-$owner-4", 32));
check('...after asking the API as before', count(array_keys($api['calls'], '/v1/links/status', true)) > $statusCalls);
unset($_SERVER['HTTP_P2FLUX_SIGNATURE']);

// The client subscribes again later (after stopping): a new link of their own, credited.
check('subscribing again opens a new link', outcome(fn () => harness_gateway($meta)->validate($sub, [])) !== $started && $api['links'] === 2);
$second = current(array_filter(rows(), static fn ($r) => $r['kind'] === 'subscribe' && $r['id'] !== $subRow['id']));
$again = $hook($meta, $second['id'], $charge(2, $hex('owner-again', 32), 0, '0x' . $second['id']));
check('...and that signup is credited', is_array($again) && $again['status'] === 'approved');

// A cancelled service: the charge is kept as credit, collection stops.
putenv('HARNESS_MODELS={"Services":{"55":{"status":"canceled"}}}');
$after = $hook($meta, $subRow['id'], $charge(1, $owner, 2, $tag));
check('charge after cancel -> credit', is_array($after) && $after['invoices'] === null);
check('...and collection stopped', in_array($owner, $api['stopped'], true));

// --- network guard ----------------------------------------------------------------------------
$live = ['environment' => 'live'] + $meta;
$testRow = current(array_filter(rows(), static fn ($r) => $r['kind'] === 'pay'));
check('a test payment is ignored once the gateway is live', outcome(fn () => harness_gateway($live)->validate(['a' => 'hook', 'k' => $testRow['id']], [])) === 'returned:null'
    && !in_array('/v1/payments/verify', $api['calls'], true));

// --- uninstall: stops active subscriptions, never logs, keeps rows unless last ----------------
$api['stopped'] = [];
$g = harness_gateway($meta);
$g->uninstall(1, false);
check('uninstall stops the active subscriptions', count($api['stopped']) >= 1);
check('uninstall keeps the records when other instances remain', count(rows()) > 0);
$GLOBALS['harness_api'] = static fn () => [500, ['error' => 'INTERNAL_ERROR']];
$broken = true;
try {
    harness_gateway($meta)->uninstall(1, true);
} catch (Throwable $e) {
    $broken = false;
}
check('uninstall with P2Flux down still finishes and drops the table', $broken && rows() === []);

@unlink(getenv('HARNESS_DB'));
@unlink(getenv('HARNESS_DB') . '.lock');
echo $failures === 0 ? "\nflows: all checks passed\n" : "\nflows: {$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
