<?php
/** Loads the gateway on the stand-in Blesta, with redirects turned into exceptions the test can read. */
require __DIR__ . '/blesta.php';
require __DIR__ . '/../../p2flux/p2flux.php';

final class HarnessRedirect extends Exception {}

final class HarnessGateway extends P2flux
{
    protected function redirect(string $url): void
    {
        throw new HarnessRedirect($url);
    }
}

function harness_gateway(array $meta): HarnessGateway
{
    Configure::set('Blesta.company_id', 1);
    Configure::set('Blesta.system_key', getenv('HARNESS_KEY') ?: 'harness-system-key');
    Configure::set('Blesta.gw_callback_url', (getenv('HARNESS_PUBLIC') ?: 'https://billing.example.com') . '/callback/gw/');
    $g = new HarnessGateway();
    $g->install();
    $g->setMeta($meta);
    $g->setCurrency('USD');

    return $g;
}
