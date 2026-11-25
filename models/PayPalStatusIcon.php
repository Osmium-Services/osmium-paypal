<?php

declare(strict_types=1);

namespace Osmium\Services\PayPal\Models;

/**
 * admin.status_icons: a card in the admin navbar while PayPal is the selected payment provider. Green when it is
 * switched on with its keys set and in live mode; amber and crossed through when it still needs configuring or is
 * in sandbox mode. Reads only local config (no PayPal call).
 */
class PayPalStatusIcon
{
    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public static function icons(array $payload): array
    {
        $selected = (string) ($payload['osmium']->config->checkout->paymentProvider ?? 'none');
        $notSelected = $selected !== PayPalPaymentProvider::ID;
        if ($notSelected) return [];

        $client = PayPalConfig::client();
        $ready = $client->isEnabled() && !$client->isSandbox();

        return [self::icon(ready: $ready, testMode: $client->isSandbox())];
    }

    /** @return array<string, mixed> */
    public static function icon(bool $ready, bool $testMode): array
    {
        $title = $ready ? 'PayPal payments are live' : ($testMode ? 'PayPal is in sandbox mode: no real payments yet' : 'PayPal needs configuring');

        return [
            'id' => 'paypal',
            'title' => $title,
            'icon' => 'bx bx-credit-card',
            'path' => 'settings/paypal/',
            'color' => $ready ? 'success' : 'warning',
            'crossed' => !$ready,
        ];
    }
}
