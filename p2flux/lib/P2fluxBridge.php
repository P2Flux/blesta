<?php
/**
 * Pure helpers for the P2Flux gateway: no Blesta classes, no I/O, so tests/bridge.php can run them
 * under plain PHP.
 */
final class P2fluxBridge
{
    /** Blesta's recurring periods in seconds. P2Flux bills on fixed intervals: a "month" is 30 days. */
    private const PERIOD_SECONDS = ['day' => 86400, 'week' => 604800, 'month' => 2592000, 'year' => 31536000];

    /** Shortest period and smallest amount a P2Flux subscription link accepts. */
    public const MIN_PERIOD = 86400;
    public const MIN_SUBSCRIPTION_UNITS = 1000000;

    /**
     * A signed, URL-safe token for the "start a payment" links on Blesta's pay page. It binds the
     * client, the invoices and the amount, so a visitor cannot change who or what a payment is for.
     */
    public static function sign(string $key, array $data): string
    {
        $body = rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)), '+/', '-_'), '=');

        return $body . '.' . hash_hmac('sha256', 'p2flux-blesta|' . $body, $key);
    }

    /**
     * What a token offers, without its expiry: the pay page renders a new token on every visit, and every
     * one of them is the same offer (so a second click reuses the payment, and a paid one stays paid).
     */
    public static function offer(array $data): string
    {
        unset($data['x']);
        ksort($data);

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** The data inside a token, or null when it was not made with this key or is malformed. */
    public static function open(string $key, string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', 'p2flux-blesta|' . $parts[0], $key), $parts[1])) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /** Seconds per billing cycle, or null when the period cannot be a P2Flux subscription. */
    public static function periodSeconds($term, $period): ?int
    {
        $term = (int) $term;
        if ($term < 1 || !isset(self::PERIOD_SECONDS[$period])) {
            return null;
        }
        $seconds = $term * self::PERIOD_SECONDS[$period];

        return $seconds >= self::MIN_PERIOD ? $seconds : null;
    }

    /** A decimal amount to integer micro-USDC, exactly (no floats): "10.5" -> 10500000. Null when not a plain amount. */
    public static function units($amount): ?int
    {
        $text = is_float($amount) ? number_format($amount, 2, '.', '') : trim((string) $amount);
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,6}))?$/', $text, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 1000000 + (int) str_pad($m[2] ?? '', 6, '0');
    }

    /** Blesta's amount (a float) as the 2-decimal string P2Flux expects. */
    public static function decimal($amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    /**
     * Whether the pay page may offer a subscription: the merchant allows it, Blesta says the invoice
     * recurs, the recurring amount is what is paid now (P2Flux charges one fixed amount per period),
     * and the link limits hold.
     */
    public static function canSubscribe(bool $allowed, $amount, ?array $recur): bool
    {
        if (!$allowed || !$recur || !isset($recur['amount'], $recur['term'], $recur['period'])) {
            return false;
        }
        $now = self::units(self::decimal($amount));
        $each = self::units(self::decimal($recur['amount']));

        return $now !== null && $now === $each && $each >= self::MIN_SUBSCRIPTION_UNITS
            && self::periodSeconds($recur['term'], $recur['period']) !== null;
    }

    /**
     * The description as a P2Flux link label (shown to the buyer): only the characters P2Flux accepts,
     * at most 60, nothing that looks like an address. Null when nothing usable is left.
     */
    public static function label(?string $description): ?string
    {
        $text = preg_replace('/[^A-Za-z0-9 .,:;\'()#&+_!?%\/-]/', '', (string) $description);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        $text = rtrim(substr($text, 0, 60));
        if ($text === '' || preg_match('/\.[A-Za-z]/', $text) === 1 || stripos($text, 'p2flux') !== false) {
            return null;
        }

        return $text;
    }

    /** A random record id; 32 hex is also the shape of a checkout link tag. */
    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Invoices as Blesta passes them ([['id' => .., 'amount' => ..]]), kept only when well formed. */
    public static function invoices($list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $invoice) {
            if (isset($invoice['id'], $invoice['amount']) && ctype_digit((string) $invoice['id']) && self::units(self::decimal($invoice['amount'])) !== null) {
                $out[] = ['id' => (string) $invoice['id'], 'amount' => self::decimal($invoice['amount'])];
            }
        }

        return $out;
    }

    /** The P2Flux checkout's origin for the environment: messages from the popup are accepted from it only. */
    public static function checkoutOrigin(string $env): string
    {
        return $env === 'test' ? 'https://pay-test.p2flux.com' : 'https://pay.p2flux.com';
    }

    public static function apiUrl(string $env): string
    {
        return $env === 'test' ? 'https://api-test.p2flux.com' : 'https://api.p2flux.com';
    }

    public static function validWallet($address): bool
    {
        return is_string($address) && preg_match('/^0x[0-9a-fA-F]{40}$/', $address) === 1
            && strtolower($address) !== '0x' . str_repeat('0', 40);
    }
}
