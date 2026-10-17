<?php
/** Loads the gateway on the stand-in Blesta, with redirects turned into exceptions the test can read. */
require __DIR__ . '/blesta.php';
require __DIR__ . '/../../p2flux/p2flux.php';

final class HarnessRedirect extends Exception {}
final class HarnessPage extends Exception {}

final class HarnessGateway extends P2flux
{
    protected function redirect(string $url): void
    {
        throw new HarnessRedirect($url);
    }

    protected function page(string $key)
    {
        throw new HarnessPage($key);
    }

    /** With $GLOBALS['harness_api'] set (a callable path, payload -> [status, body]), a scripted P2Flux API. */
    protected function client(string $env): P2FluxBlesta\Vendor\P2Flux\P2FluxClient
    {
        if (!isset($GLOBALS['harness_api'])) {
            return parent::client($env);
        }

        return new P2FluxBlesta\Vendor\P2Flux\P2FluxClient(['apiUrl' => 'https://api-test.p2flux.com', 'transport' => static function ($url, $payload) {
            return ($GLOBALS['harness_api'])((string) parse_url($url, PHP_URL_PATH), $payload);
        }]);
    }
}

function harness_gateway(array $meta): HarnessGateway
{
    // Blesta always serves the callback over HTTP; log() needs the request URL.
    $_SERVER['REQUEST_URI'] ??= '/callback/gw/1/p2flux/';
    Configure::set('Blesta.company_id', 1);
    Configure::set('Blesta.system_key', getenv('HARNESS_KEY') ?: 'harness-system-key');
    Configure::set('Blesta.gw_callback_url', (getenv('HARNESS_PUBLIC') ?: 'https://billing.example.com') . '/callback/gw/');
    $g = new HarnessGateway();
    $g->install();
    $g->setMeta($meta);
    $g->setCurrency('USD');

    return $g;
}
