<?php
/** `php -S` router: the gateway callback, as Blesta's Callback::gw would call it (webhooks reach it through a tunnel). */
require __DIR__ . '/gateway.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!str_contains($path, '/callback/gw/1/p2flux')) {
    http_response_code(404);
    return true;
}
$g = harness_gateway(json_decode((string) getenv('HARNESS_META'), true));
$result = $g->validate($_GET, $_POST);
file_put_contents(getenv('HARNESS_OUT') ?: sys_get_temp_dir() . '/p2flux-blesta-hooks.jsonl', json_encode(['get' => $_GET, 'result' => $result, 'errors' => $g->Input->errors(), 'code' => http_response_code()]) . "\n", FILE_APPEND);
return true;
