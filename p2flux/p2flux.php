<?php
/**
 * P2Flux gateway for Blesta: USDC payments and subscriptions on Base, non-custodial.
 *
 * The buyer pays in P2Flux's hosted checkout (a popup); the money goes straight from the buyer's wallet
 * to the merchant's wallet, never through P2Flux or Blesta. Blesta learns about a payment through the
 * gateway callback, which only ever records what the P2Flux API itself confirms on chain:
 *
 *   ?a=pay|subscribe&t=<signed token>  start: mint the payment (or subscription link), go to the checkout
 *   ?a=return&r=<reference>&tx=<hash>  the buyer's browser, right after paying (fast path)
 *   ?a=hook&k=<record>                 P2Flux's webhook (authoritative; retried for ~18 hours)
 *
 * Client, invoices and amount come from this gateway's own record, never from query parameters;
 * the amount paid comes from the API's verdict. transaction_id is the chain transaction hash, so a
 * repeated callback edits the same Blesta transaction instead of adding one.
 *
 * @link https://p2flux.com/docs/blesta.html
 */
use P2FluxBlesta\Vendor\P2Flux\P2FluxClient;
use P2FluxBlesta\Vendor\P2Flux\P2FluxException;

class P2flux extends NonmerchantGateway
{
    private const TABLE = 'p2flux_records';

    /** @var array Gateway settings */
    private $meta;

    public function __construct()
    {
        foreach (['P2FluxException', 'ChargeResult', 'CurlTransport', 'P2FluxClient'] as $file) {
            require_once dirname(__FILE__) . DS . 'vendor' . DS . 'p2flux' . DS . $file . '.php';
        }
        require_once dirname(__FILE__) . DS . 'lib' . DS . 'P2fluxBridge.php';

        Language::loadLang('p2flux', null, dirname(__FILE__) . DS . 'language' . DS);
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Loader::loadComponents($this, ['Input', 'Record']);
    }

    // --- install --------------------------------------------------------------------------------

    public function install()
    {
        try {
            $this->Record
                ->setField('id', ['type' => 'varchar', 'size' => 32])
                ->setField('company_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('kind', ['type' => 'varchar', 'size' => 12])
                ->setField('token_hash', ['type' => 'char', 'size' => 64])
                ->setField('client_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('invoices', ['type' => 'text', 'is_null' => true, 'default' => null])
                ->setField('amount', ['type' => 'varchar', 'size' => 32])
                ->setField('env', ['type' => 'varchar', 'size' => 8])
                ->setField('intent', ['type' => 'text', 'is_null' => true, 'default' => null])
                ->setField('reference', ['type' => 'varchar', 'size' => 66, 'is_null' => true, 'default' => null])
                ->setField('link_id', ['type' => 'varchar', 'size' => 34, 'is_null' => true, 'default' => null])
                ->setField('link', ['type' => 'text', 'is_null' => true, 'default' => null])
                ->setField('manage', ['type' => 'text', 'is_null' => true, 'default' => null])
                ->setField('service_ids', ['type' => 'text', 'is_null' => true, 'default' => null])
                ->setField('subscription_id', ['type' => 'varchar', 'size' => 66, 'is_null' => true, 'default' => null])
                ->setField('state', ['type' => 'varchar', 'size' => 16, 'is_null' => true, 'default' => null])
                ->setField('tx_hash', ['type' => 'varchar', 'size' => 66, 'is_null' => true, 'default' => null])
                ->setField('created', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('expires', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0])
                ->setKey(['id'], 'primary')
                ->setKey(['token_hash'], 'index')
                ->create(self::TABLE, true);
        } catch (Exception $e) {
            $this->Input->setErrors(['db' => ['create' => $e->getMessage()]]);
        }
    }

    public function uninstall($gateway_id, $last_instance)
    {
        // The records are the only proof of which wallet payment belongs to which invoice: kept unless
        // this is the last P2Flux gateway installed anywhere.
        if ($last_instance) {
            $this->Record->drop(self::TABLE);
        }
    }

    // --- settings -------------------------------------------------------------------------------

    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function encryptableFields()
    {
        // P2Flux has no API key or secret: nothing to encrypt.
        return [];
    }

    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView('settings', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('meta', $meta);
        $this->view->set('environments', [
            'live' => Language::_('P2flux.environment.live', true),
            'test' => Language::_('P2flux.environment.test', true),
        ]);
        $this->view->set('callback_url', $this->callbackUrl([], $meta ?? []));

        return $this->view->fetch();
    }

    public function editSettings(array $meta)
    {
        foreach (['gas_mode', 'subscriptions'] as $flag) {
            $meta[$flag] = (($meta[$flag] ?? 'false') === 'true') ? 'true' : 'false';
        }
        $meta['recipient'] = trim($meta['recipient'] ?? '');
        $meta['callback_base'] = rtrim(trim($meta['callback_base'] ?? ''), '/');

        $this->Input->setRules([
            'recipient' => ['valid' => [
                'rule' => [[P2fluxBridge::class, 'validWallet']],
                'message' => Language::_('P2flux.!error.recipient.valid', true),
            ]],
            'environment' => ['valid' => [
                'rule' => ['in_array', ['live', 'test']],
                'message' => Language::_('P2flux.!error.environment.valid', true),
            ]],
            'callback_base' => ['valid' => [
                'if_set' => true,
                'rule' => [[$this, 'validCallbackBase']],
                'message' => Language::_('P2flux.!error.callback_base.valid', true),
            ]],
        ]);
        $this->Input->validates($meta);

        return $meta;
    }

    /** Empty, or an https base URL ending in .../callback/gw (no query, no fragment). */
    public function validCallbackBase($value)
    {
        return $value === '' || preg_match('#^https://[^\s?\#]+/callback/gw$#', (string) $value) === 1;
    }

    // --- pay page -------------------------------------------------------------------------------

    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        $env = $this->env();
        $data = [
            'co' => (int) Configure::get('Blesta.company_id'),
            'c' => (int) ($contact_info['client_id'] ?? 0),
            'i' => P2fluxBridge::invoices($invoice_amounts),
            'a' => P2fluxBridge::decimal($amount),
            'e' => $env,
            'd' => (string) ($options['description'] ?? ''),
        ];
        $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));
        Loader::loadHelpers($this, ['Form', 'Html']);

        // Nothing is created at P2Flux until the buyer clicks: this page renders on every visit.
        $this->view->set('pay_url', $this->callbackUrl(['a' => 'pay', 't' => P2fluxBridge::sign($this->key(), $data)]));
        $recur = $options['recur'] ?? null;
        if (P2fluxBridge::canSubscribe(($this->meta['subscriptions'] ?? 'true') === 'true', $amount, $recur)) {
            $data['p'] = P2fluxBridge::periodSeconds($recur['term'], $recur['period']);
            $data['t'] = (int) $recur['term'];
            $data['u'] = (string) $recur['period'];
            $this->view->set('subscribe_url', $this->callbackUrl(['a' => 'subscribe', 't' => P2fluxBridge::sign($this->key(), $data)]));
            $this->view->set('every', $this->every((int) $recur['term'], (string) $recur['period']));
        }
        $this->view->set('origin', P2fluxBridge::checkoutOrigin($env));
        $this->view->set('return_url', (string) ($options['return_url'] ?? ''));
        $this->view->set('callback_url', $this->callbackUrl(['a' => 'return']));
        $this->view->set('test_mode', $env === 'test');

        return $this->view->fetch();
    }

    // --- callback -------------------------------------------------------------------------------

    public function validate(array $get, array $post)
    {
        $action = (string) ($get['a'] ?? '');
        if ($action === 'pay' || $action === 'subscribe') {
            return $this->start($action, (string) ($get['t'] ?? ''));
        }
        $record = $this->record($get);
        if (!$record) {
            $this->Input->setErrors($this->getCommonError('invalid'));

            return;
        }
        try {
            if ($action === 'return' && $record->kind === 'pay') {
                return $this->confirmReturn($record, (string) ($get['tx'] ?? ''));
            }
            if ($action === 'hook') {
                return $this->confirmHook($record, (string) file_get_contents('php://input'));
            }
        } catch (P2FluxException $e) {
            // Not final yet (still confirming) or P2Flux unreachable: answer 5xx so the webhook comes again.
            $this->log($this->requestUri(), json_encode(['status' => $e->status, 'action' => $e->action]), 'output', false);
            if ($action === 'hook') {
                http_response_code(503);
            }

            return;
        }
        $this->Input->setErrors($this->getCommonError('invalid'));
    }

    public function success(array $get, array $post)
    {
        $record = $this->record($get);
        if (!$record) {
            return;
        }

        return [
            'client_id' => (int) $record->client_id,
            'amount' => $record->amount,
            'currency' => 'USD',
            'invoices' => json_decode((string) $record->invoices, true) ?: null,
            // Shown only: the money is recorded by validate() once P2Flux confirms it on chain.
            'status' => !empty($record->tx_hash) ? 'approved' : 'pending',
            'transaction_id' => ($record->tx_hash ?? null) ?: null,
            'parent_transaction_id' => null,
        ];
    }

    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        // A P2Flux refund is a transfer the merchant signs in their own wallet, on the P2Flux refund page.
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    public function void($reference_id, $transaction_id, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    // --- flows ----------------------------------------------------------------------------------

    /** The buyer clicked: create (or reuse) the P2Flux payment or subscription link and open the checkout. */
    private function start(string $action, string $token)
    {
        $data = P2fluxBridge::open($this->key(), $token);
        if (!$data || (int) $data['co'] !== (int) Configure::get('Blesta.company_id') || ($action === 'subscribe' && empty($data['p']))) {
            $this->Input->setErrors($this->getCommonError('invalid'));

            return;
        }
        $hash = hash('sha256', $action . '|' . $token);
        $existing = $this->Record->select()->from(self::TABLE)->where('token_hash', '=', $hash)
            ->order(['created' => 'DESC'])->fetch();
        $client = $this->client($data['e']);

        try {
            if ($action === 'pay') {
                // An unpaid intent lives an hour: reuse it while a minute is left, so a second click is the same payment.
                $record = $existing && empty($existing->tx_hash) && (int) ($existing->expires ?? 0) > time() + 60 ? $existing : null;
                if (!$record) {
                    $record = $this->newRecord('pay', $hash, $data);
                    $created = $this->createPayment($client, $data, $record->id);
                    $this->Record->where('id', '=', $record->id)->update(self::TABLE, [
                        'intent' => $created['intent'], 'reference' => strtolower($created['reference']), 'expires' => (int) $created['expires_at'],
                    ]);
                    $record->intent = $created['intent'];
                }
                $this->redirect($client->checkoutLink('pay', $record->intent));
            }

            // One subscription link per signed offer: a second click opens the same link.
            $record = $existing ?: $this->newRecord('subscribe', $hash, $data);
            if (empty($record->link)) {
                $link = $client->createPaymentLink(array_filter([
                    'kind' => 'subscription',
                    'recipient' => $this->meta['recipient'],
                    'amount' => $data['a'],
                    'period' => (int) $data['p'],
                    'label' => P2fluxBridge::label($data['d']),
                    'notify_url' => $this->callbackUrl(['a' => 'hook', 'k' => $record->id]),
                ], static fn ($v) => $v !== null));
                $this->Record->where('id', '=', $record->id)->update(self::TABLE, [
                    'link' => $link['link'], 'manage' => $link['manage'], 'link_id' => strtolower($link['id']),
                    'service_ids' => json_encode($this->serviceIds($data['i'])), 'expires' => (int) $link['expires_at'],
                ]);
                $record->link = $link['link'];
            }
            // The tag ties every subscription event of this signup to this record.
            $this->redirect($client->checkoutLink('link', $record->link) . '~0x' . $record->id);
        } catch (P2FluxException $e) {
            $this->log($this->requestUri(), json_encode(['status' => $e->status, 'action' => $e->action, 'detail' => $e->raw['detail'] ?? null]), 'output', false);
            $this->Input->setErrors(['p2flux' => ['start' => Language::_($e->status === 'RECIPIENT_SANCTIONED' || $e->action === 'INVALID_REQUEST'
                ? 'P2flux.!error.start.config' : 'P2flux.!error.start.unavailable', true)]]);
        }
    }

    private function createPayment(P2FluxClient $client, array $data, string $recordId): array
    {
        $terms = [
            'recipient' => $this->meta['recipient'],
            'amount' => $data['a'],
            'notify_url' => $this->callbackUrl(['a' => 'hook', 'k' => $recordId]),
        ];
        if (($this->meta['gas_mode'] ?? 'false') === 'true') {
            try {
                return $client->createPayment($terms + ['gas_payment_mode' => 'payment_token']);
            } catch (P2FluxException $e) {
                // Too small for the fixed network fee, or not offered here: an ordinary payment still works.
                if (!str_starts_with($e->status, 'PAYMENT_TOKEN_GAS') && $e->status !== 'AMOUNT_OUT_OF_BOUNDS') {
                    throw $e;
                }
            }
        }

        return $client->createPayment($terms);
    }

    /** The buyer's browser reports a transaction: ask the API whether it pays this record's intent. */
    private function confirmReturn($record, string $tx)
    {
        if (preg_match('/^0x[0-9a-fA-F]{64}$/', $tx) !== 1 || empty($record->intent)) {
            $this->Input->setErrors($this->getCommonError('invalid'));

            return;
        }
        $verdict = $this->client($record->env)->verifyPayment($record->intent, $tx);
        $this->log($this->requestUri(), json_encode($verdict), 'output', ($verdict['valid'] ?? false) === true);
        if (($verdict['valid'] ?? false) !== true) {
            // Still confirming: nothing recorded now, the webhook (or the next return) records it.
            if (($verdict['code'] ?? '') !== 'PAYMENT_CONFIRMING') {
                $this->Input->setErrors($this->getCommonError('invalid'));
            }

            return;
        }
        if (strtolower((string) ($verdict['reference'] ?? '')) !== strtolower((string) $record->reference)) {
            $this->Input->setErrors($this->getCommonError('invalid'));

            return;
        }

        return $this->paid($record, (string) $verdict['tx_hash'], (string) $verdict['amount']);
    }

    /** P2Flux's webhook: confirmed with the API by the SDK before anything is recorded. */
    private function confirmHook($record, string $body)
    {
        $client = $this->client($record->env);
        $expect = $record->kind === 'pay'
            ? ['reference' => (string) $record->reference]
            : ['link_id' => (string) $record->link_id, 'manage' => (string) $record->manage];
        if (($expect['reference'] ?? $expect['link_id']) === '') {
            return;
        }
        $result = $client->handleNotification($body, $expect);
        $this->log($this->requestUri(), json_encode(['type' => $result['type'], 'confirmed' => $result['confirmed'], 'reason' => $result['reason'] ?? null]), 'output', $result['confirmed']);
        if (!$result['confirmed']) {
            // Not this record's, not the latest period, or refused: nothing to record (answered 200, not retried).
            return;
        }
        if ($result['type'] === 'payment.paid') {
            return $this->paid($record, (string) $result['payment']['tx_hash'], (string) $result['payment']['amount']);
        }

        $subscriber = $result['subscriber'];
        $this->Record->where('id', '=', $record->id)->update(self::TABLE, [
            'subscription_id' => strtolower((string) $subscriber['subscription_id']),
            'state' => (string) $subscriber['state'],
        ]);
        if ($result['type'] !== 'subscription.charged') {
            return;
        }
        $tx = strtolower((string) ($subscriber['last_tx'] ?? ''));
        if (preg_match('/^0x[0-9a-f]{64}$/', $tx) !== 1) {
            return;
        }
        $period = (int) $subscriber['last_period'];
        if ($period > 0 && $this->anyServiceCanceled($record)) {
            // The service was cancelled in Blesta: keep the money as credit and stop further collection.
            $stopped = $client->stopPaymentLink((string) $record->manage, (string) $subscriber['subscription_id']);
            $this->log($this->requestUri(), json_encode(['stopped' => $stopped]), 'output', true);
        }

        return [
            'client_id' => (int) $record->client_id,
            'amount' => $record->amount,
            'currency' => 'USD',
            // The signup charge pays the invoice it was started from; renewals become credit, which Blesta
            // applies to the open renewal invoice (Auto Apply Credits).
            'invoices' => $period === 0 ? (json_decode((string) $record->invoices, true) ?: null) : null,
            'status' => 'approved',
            'reference_id' => strtolower((string) $subscriber['subscription_id']) . ':' . $period,
            'transaction_id' => $tx,
            'parent_transaction_id' => null,
        ];
    }

    /** A confirmed one-time payment as a Blesta transaction. A different amount is credit, never applied. */
    private function paid($record, string $tx, string $amount)
    {
        $tx = strtolower($tx);
        $this->Record->where('id', '=', $record->id)->update(self::TABLE, ['tx_hash' => $tx, 'state' => 'paid']);
        $exact = P2fluxBridge::units($amount) === P2fluxBridge::units($record->amount);

        return [
            'client_id' => (int) $record->client_id,
            'amount' => $exact ? $record->amount : P2fluxBridge::decimal($amount),
            'currency' => 'USD',
            'invoices' => $exact ? (json_decode((string) $record->invoices, true) ?: null) : null,
            'status' => 'approved',
            'reference_id' => (string) $record->reference,
            'transaction_id' => $tx,
            'parent_transaction_id' => null,
        ];
    }

    // --- helpers --------------------------------------------------------------------------------

    private function newRecord(string $kind, string $hash, array $data)
    {
        $record = (object) [
            'id' => P2fluxBridge::newId(),
            'company_id' => (int) $data['co'],
            'kind' => $kind,
            'token_hash' => $hash,
            'client_id' => (int) $data['c'],
            'invoices' => json_encode($data['i']),
            'amount' => $data['a'],
            'env' => $data['e'],
            'created' => time(),
            'expires' => 0,
            'intent' => null, 'reference' => null, 'link' => null, 'link_id' => null, 'manage' => null, 'tx_hash' => null,
        ];
        $this->Record->insert(self::TABLE, array_filter((array) $record, static fn ($v) => $v !== null));

        return $record;
    }

    /** This company's record named by `k` (record id, from our own URLs) or `r` (payment reference, from the checkout). */
    private function record(array $get)
    {
        $id = (string) ($get['k'] ?? '');
        $reference = strtolower((string) ($get['r'] ?? ''));
        if (preg_match('/^[0-9a-f]{32}$/', $id) === 1) {
            $this->Record->select()->from(self::TABLE)->where('id', '=', $id);
        } elseif (preg_match('/^0x[0-9a-f]{64}$/', $reference) === 1) {
            $this->Record->select()->from(self::TABLE)->where('reference', '=', $reference);
        } else {
            return null;
        }

        return $this->Record->where('company_id', '=', (int) Configure::get('Blesta.company_id'))->fetch() ?: null;
    }

    private function serviceIds(array $invoices): array
    {
        Loader::loadModels($this, ['Invoices']);
        $ids = [];
        foreach ($invoices as $invoice) {
            foreach (($this->Invoices->get((int) $invoice['id'])->line_items ?? []) as $line) {
                if (!empty($line->service_id)) {
                    $ids[] = (int) $line->service_id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function anyServiceCanceled($record): bool
    {
        Loader::loadModels($this, ['Services']);
        foreach (json_decode((string) $record->service_ids, true) ?: [] as $id) {
            if ((($this->Services->get((int) $id)->status) ?? '') === 'canceled') {
                return true;
            }
        }

        return false;
    }

    private function client(string $env): P2FluxClient
    {
        // 25 s: under the 30 s max_execution_time of many hosts.
        return new P2FluxClient(['apiUrl' => P2fluxBridge::apiUrl($env), 'timeout' => 25]);
    }

    private function env(): string
    {
        return ($this->meta['environment'] ?? 'live') === 'test' ? 'test' : 'live';
    }

    /** Blesta's own secret, so tokens need no setting of their own. */
    private function key(): string
    {
        return 'p2flux|' . (string) Configure::get('Blesta.system_key');
    }

    private function callbackUrl(array $query, ?array $meta = null): string
    {
        $base = (string) (($meta ?? $this->meta)['callback_base'] ?? '');
        $base = $base !== '' ? $base . '/' : (string) Configure::get('Blesta.gw_callback_url');

        return $base . Configure::get('Blesta.company_id') . '/p2flux/' . ($query ? '?' . http_build_query($query) : '');
    }

    private function every(int $term, string $period): string
    {
        return Language::_('P2flux.every.' . $period . ($term === 1 ? '' : 's'), true, $term);
    }

    protected function redirect(string $url): void
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    private function requestUri(): ?string
    {
        // Logged without the query: it carries the signed token and record ids.
        return isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : null;
    }
}
