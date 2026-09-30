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
        );

        return ['ref' => $ref, 'test_mode' => $client->isSandbox()];
    }

    /**
     * payment.confirm - capture the approved PayPal order and report the outcome.
     *
     * @return array{status: string, amount: float, currency: string, capture_ref: string, payer_email: ?string}|null
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
        ];
    }
}
