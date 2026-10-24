<?php

declare(strict_types=1);

namespace Osmium\Services\PayPal\Models;

/**
 * Adapts PayPal to core's payment.* hooks (see ServiceHooks / ShopPaymentProviders).
 *
 * Every handler answers only for provider id 'paypal' and returns null for
 * anything else, so another payment service can coexist.
 */
class PayPalPaymentProvider
{
    public const ID = 'paypal';

    /**
     * payment.providers - advertise PayPal and whether it can take money now.
     */
    public static function provider(array $payload): array
    {
        $client = PayPalConfig::client();

        return [
            'id' => self::ID,
            'label' => 'PayPal',
            'logo' => '/assets/services/paypal/logo.png', // Shown next to the order in admin
            'ready' => $client->isEnabled(),
            'frontend' => ['clientId' => $client->clientId()], // Public by design; the JS SDK needs it
            'settingsRoute' => 'settings/paypal/',
        ];
    }

    /**
     * payment.create - create a PayPal order for an order already written.
     *
     * @return array{ref: string, test_mode: bool}|null
     * @throws \Osmium\Modules\Checkout\Services\ShopPaymentException
     */
    public static function create(array $payload): ?array
    {
        $notOurs = ($payload['provider'] ?? '') !== self::ID;
        if ($notOurs) return null;

        $client = PayPalConfig::client();
        $ref = $client->createOrder(
            order: $payload['order'],
            totals: $payload['totals'],
            lines: $payload['totals']['lines'],
            finance: \Osmium\Core\Library\StoreFinance::forOsmium($payload['osmium']),
        );

        return ['ref' => $ref, 'test_mode' => $client->isSandbox()];
    }

    /**
     * payment.confirm - capture the approved PayPal order and report the outcome.
     *
     * @return array{status: string, amount: float, currency: string, capture_ref: string, payer_email: ?string, payment_method: ?string}|null
     * @throws \Osmium\Modules\Checkout\Services\ShopPaymentException
     */
    public static function confirm(array $payload): ?array
    {
        $notOurs = ($payload['provider'] ?? '') !== self::ID;
        if ($notOurs) return null;

        $capture = PayPalConfig::client()->captureOrder((string) $payload['ref']);
        $detail = $capture['purchase_units'][0]['payments']['captures'][0] ?? [];

        return [
            'status' => match ((string) ($detail['status'] ?? '')) {
                'COMPLETED' => 'completed',
                'PENDING' => 'pending',
                default => 'failed',
            },
            'amount' => (float) ($detail['amount']['value'] ?? 0),
            'currency' => (string) ($detail['amount']['currency_code'] ?? ''),
            'capture_ref' => (string) ($detail['id'] ?? ''),
            'payer_email' => $capture['payer']['email_address'] ?? null,
            'payment_method' => self::methodLabel((array) ($capture['payment_source'] ?? [])),
        ];
    }

    /**
     * How the customer paid, from the capture's payment_source: a PayPal account,
     * or a card paid through PayPal. Null when PayPal says neither.
     *
     * The PayPal-account shape was seen in a real sandbox capture. The card shape
     * (brand, last_digits) is from PayPal's Orders v2 reference and has not been
     * seen in a capture here, so a card with those fields missing is still "Card".
     */
    public static function methodLabel(array $source): ?string
    {
        if (isset($source['paypal'])) return 'PayPal account';

        if (!isset($source['card'])) return null;

        $brand = \ucfirst(\strtolower((string) ($source['card']['brand'] ?? '')));
        $lastDigits = \preg_replace('/\D/', '', (string) ($source['card']['last_digits'] ?? ''));
        if ($brand === '' || $lastDigits === '') return 'Card via PayPal';

        return "Card via PayPal ({$brand} ••{$lastDigits})";
    }
}
