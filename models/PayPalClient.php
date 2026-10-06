<?php

declare(strict_types=1);

namespace Osmium\Services\PayPal\Models;

use Osmium\Core\Library\StoreFinance;

use Osmium\Core\Library\Cache;
use Osmium\Modules\Checkout\Services\ShopPaymentException;

/**
 * PayPalClient
 *
 * A thin client for the PayPal Orders v2 API. Deliberately thin: it knows how
 * to talk to PayPal and nothing about our basket, our totals or our order
 * lifecycle. Swapping provider means rewriting this class and the checkout
 * script, and nothing else — which is only true while it stays this narrow.
 *
 * Everything monetary passed in here has already been computed server-side
 * from the database. Nothing in this class decides what anything costs.
 */
class PayPalClient
{
    private const LIVE_BASE = 'https://api-m.paypal.com';
    private const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';
    private const TIMEOUT_SECONDS = 20;

    public function __construct(
        private object $config,
        private ?Cache $tokenCache = null,
    ) {
        $this->tokenCache ??= new Cache(namespace: 'paypal', ttl: 300);
    }

    /**
     * Is PayPal switched on and actually usable?
     *
     * Enabled with empty credentials is not usable, and treating it as such
     * would put a dead payment button in front of a customer.
     */
    public function isEnabled(): bool
    {
        $switchedOn = !empty($this->config->enabled);
        if (!$switchedOn) return false;

        return $this->clientId() !== '' && $this->secret() !== '';
    }

    /**
     * Client id for the JS SDK. Public by design — the secret never leaves here.
     */
    public function clientId(): string
    {
        $key = $this->isSandbox() ? 'sandboxClientId' : 'liveClientId';

        return (string)($this->config->$key ?? '');
    }

    public function isSandbox(): bool
    {
        return ($this->config->mode ?? 'sandbox') !== 'live';
    }

    /**
     * Create a PayPal order.
     *
     * @param array $order Stored order row (order_ref, addresses, name)
     * @param array $totals Server-computed totals
     * @param array $lines Basket lines, already priced
     * @param StoreFinance $finance The store's country: every order ships there (UK-only for now)
     * @return string PayPal's order id
     * @throws ShopPaymentException
     */
    public function createOrder(array $order, array $totals, array $lines, StoreFinance $finance): string
    {
        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [$this->buildPurchaseUnit(order: $order, totals: $totals, lines: $lines, finance: $finance)],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'brand_name' => (string)(($this->config->brandName ?? '') ?: 'Shop'),
                        'shipping_preference' => 'SET_PROVIDED_ADDRESS', // The buyer cannot substitute an address we never priced or validated
                        'user_action' => 'PAY_NOW',
                    ],
                ],
            ],
        ];

        $response = $this->request(
            method: 'POST',
            path: '/v2/checkout/orders',
            body: $body,
            headers: ['PayPal-Request-Id: ' . $order['order_ref']], // Idempotency: a retried create returns the same PayPal order rather than a second one
        );

        $orderIdMissing = empty($response['id']);
        if ($orderIdMissing) throw new ShopPaymentException('PayPal did not return an order id');

        return (string)$response['id'];
    }

    /**
     * Capture a previously created order.
     *
     * @param string $paypalOrderId PayPal's order id
     * @return array The capture response
     * @throws ShopPaymentException
     */
    public function captureOrder(string $paypalOrderId): array
    {
        return $this->request(
            method: 'POST',
            path: '/v2/checkout/orders/' . \rawurlencode($paypalOrderId) . '/capture',
            body: new \stdClass(), // PayPal wants an empty JSON object here, not an empty array, which would encode as []
            headers: ['PayPal-Request-Id: capture-' . $paypalOrderId], // A double-clicked button must not double-charge
        );
    }

    /**
     * Read an order back from PayPal.
     *
     * Used by the webhook path, where we are told about a payment rather than
     * making one, and must not trust the notification's own figures.
     */
    public function getOrder(string $paypalOrderId): array
    {
        return $this->request(
            method: 'GET',
            path: '/v2/checkout/orders/' . \rawurlencode($paypalOrderId),
            body: null,
        );
    }

    /**
     * Verify a webhook signature with PayPal.
     *
     * An unverified webhook is an unauthenticated write to order state, so this
     * failing must mean the event is discarded, never "assume it is genuine".
     *
     * @param array $headers Request headers, as name => value
     * @param string $rawBody The exact bytes received
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): bool
    {
        $webhookId = (string)($this->config->webhookId ?? '');

        $webhookNotConfigured = $webhookId === '';
        if ($webhookNotConfigured) {
            \error_log('PayPal webhook received but no webhookId is configured — event discarded');
            return false;
        }

        $header = fn(string $name) => (string)($headers[$name] ?? $headers[\strtolower($name)] ?? '');

        $event = \json_decode(json: $rawBody, associative: true);

        $bodyUnreadable = !\is_array($event);
        if ($bodyUnreadable) return false;

        try {
            $response = $this->request(method: 'POST', path: '/v1/notifications/verify-webhook-signature', body: [
                'auth_algo' => $header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $header('PAYPAL-CERT-URL'),
                'transmission_id' => $header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $webhookId,
                'webhook_event' => $event,
            ]);
        } catch (ShopPaymentException $e) {
            \error_log('PayPal webhook verification call failed: ' . $e->getMessage());
            return false;
        }

        return ($response['verification_status'] ?? '') === 'SUCCESS';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Request body
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the purchase unit.
     *
     * PayPal rejects a breakdown that does not reconcile exactly, and its rules
     * are stricter than simply adding up:
     *
     * - item_total must equal the sum of (unit_amount x quantity)
     * - tax_total must equal the sum of (item tax x quantity), items only
     * - amount must equal item_total + tax_total + shipping
     *
     * The second rule is the awkward one, because **PayPal has no field for tax
     * on shipping**. Delivery VAT therefore travels inside the shipping figure,
     * which is quoted gross here while our own order stores it net with the VAT
     * in tax_amount. Both describe the same money — a customer paying £128.34
     * pays £128.34 — they just divide it up differently.
     *
     * ShopTotalsService takes VAT per unit rather than on the rounded line
     * total precisely so the second rule holds for any quantity.
     */
    private function buildPurchaseUnit(array $order, array $totals, array $lines, StoreFinance $finance): array
    {
        $currency = (string)$order['currency'];

        $items = [];
        $itemTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($lines as $line) {
            $items[] = [
                'name' => $this->truncate((string)$line['product_title'], 127),
                'description' => $this->truncate((string)($line['product_options'] ?? ''), 127) ?: null,
                'sku' => $this->truncate((string)$line['product_ref'], 127),
                'quantity' => (string)(int)$line['quantity'],
                'unit_amount' => $this->money($line['unit_price_exc_tax'], $currency),
                'tax' => $this->money($this->unitTax($line), $currency),
            ];

            $itemTotal += (float)$line['line_total_exc_tax'];
            $taxTotal += (float)$line['line_tax_amount'];
        }

        $shippingGross = \round(
            num: ((float)$totals['delivery_exc_tax']) + ((float)$totals['delivery_tax_amount']),
            precision: 2,
        ); // Gross, because PayPal's breakdown has no shipping-tax field and tax_total may only carry item tax

        $grandTotal = \round(num: $itemTotal + $taxTotal + $shippingGross, precision: 2);

        return [
            'reference_id' => (string)$order['order_ref'],
            'custom_id' => (string)$order['order_ref'],
            'invoice_id' => (string)$order['order_ref'], // Shown on the buyer's PayPal record, and blocks a duplicate payment for the same order
            'description' => $this->truncate('Order ' . $order['order_ref'], 127),
            'items' => \array_map(callback: fn($item) => \array_filter($item, fn($v) => $v !== null), array: $items),
            'amount' => [
                'currency_code' => $currency,
                'value' => \number_format(num: $grandTotal, decimals: 2, decimal_separator: '.', thousands_separator: ''),
                'breakdown' => [
                    'item_total' => $this->money($itemTotal, $currency),
                    'tax_total' => $this->money($taxTotal, $currency), // Item tax only — delivery VAT is inside shipping
                    'shipping' => $this->money($shippingGross, $currency),
                ],
            ],
            'shipping' => [
                'type' => 'SHIPPING',
                'name' => ['full_name' => $this->truncate((string)$order['delivery_name'], 300)],
                'address' => \array_filter([
                    'address_line_1' => $this->truncate((string)$order['delivery_address1'], 300),
                    'address_line_2' => $this->truncate((string)($order['delivery_address2'] ?? ''), 300) ?: null,
                    'admin_area_2' => $this->truncate((string)$order['delivery_city'], 120),
                    'admin_area_1' => $this->truncate((string)($order['delivery_county'] ?? ''), 300) ?: null,
                    'postal_code' => (string)$order['delivery_postcode'],
                    'country_code' => $finance->country(),
                ], fn($v) => $v !== null),
            ],
        ];
    }

    /**
     * Per-unit tax.
     *
     * Divides the line's tax back down, which is exact because
     * ShopTotalsService built that tax as (unit tax x quantity) in the first
     * place. PayPal checks this multiplies back up to tax_total, so any
     * rounding introduced here would fail the order.
     */
    private function unitTax(array $line): float
    {
        $quantity = (int)$line['quantity'];

        $noQuantity = $quantity < 1;
        if ($noQuantity) return 0.0;

        return \round(num: ((float)$line['line_tax_amount']) / $quantity, precision: 2);
    }

    private function money(float $amount, string $currency): array
    {
        return [
            'currency_code' => $currency,
            'value' => \number_format(num: $amount, decimals: 2, decimal_separator: '.', thousands_separator: ''),
        ];
    }

    private function truncate(string $value, int $length): string
    {
        return \mb_substr(string: \trim($value), start: 0, length: $length);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Transport
    // ─────────────────────────────────────────────────────────────────────────

    private function secret(): string
    {
        $key = $this->isSandbox() ? 'sandboxSecret' : 'liveSecret';

        return (string)($this->config->$key ?? '');
    }

    private function baseUrl(): string
    {
        return $this->isSandbox() ? self::SANDBOX_BASE : self::LIVE_BASE;
    }

    /**
     * An OAuth2 access token, cached until shortly before it expires.
     *
     * @throws ShopPaymentException
     */
    private function accessToken(): string
    {
        $cacheKey = $this->isSandbox() ? 'token-sandbox' : 'token-live';

        $cached = $this->tokenCache->get(key: $cacheKey, fallback: fn() => $this->requestAccessToken());

        $tokenUnusable = empty($cached['token']) || $cached['expires_at'] <= \time();
        if ($tokenUnusable) {
            $fresh = $this->requestAccessToken();
            $this->tokenCache->set(key: $cacheKey, value: $fresh);
            return $fresh['token'];
        }

        return $cached['token'];
    }

    /**
     * @return array{token: string, expires_at: int}
     */
    private function requestAccessToken(): array
    {
        $credentialsMissing = $this->clientId() === '' || $this->secret() === '';
        if ($credentialsMissing) throw new ShopPaymentException('PayPal credentials are not configured');

        $curl = \curl_init($this->baseUrl() . '/v1/oauth2/token');

        \curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_USERPWD => $this->clientId() . ':' . $this->secret(),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);

        $raw = \curl_exec($curl);
        $status = (int)\curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = \curl_error($curl);

        $callFailed = $raw === false;
        if ($callFailed) throw new ShopPaymentException('Could not reach PayPal: ' . $curlError);

        $response = \json_decode(json: (string)$raw, associative: true);

        $tokenRefused = $status !== 200 || empty($response['access_token']);
        if ($tokenRefused) {
            \error_log('PayPal token request failed with status ' . $status . ': ' . (string)$raw);

            $reason = (string)($response['error'] ?? 'unknown');
            throw new ShopPaymentException("PayPal rejected our credentials ({$status}: {$reason})"); // Only the error code travels, never the body, which echoes back what was sent
        }

        $expiresIn = (int)($response['expires_in'] ?? 300);

        return [
            'token' => (string)$response['access_token'],
            'expires_at' => \time() + \max(60, $expiresIn - 60), // Renew a minute early rather than racing the expiry
        ];
    }

    /**
     * Make an authenticated API call.
     *
     * @param string $method HTTP method
     * @param string $path Path below the API base
     * @param mixed $body Body to JSON encode, or null for none
     * @param array $headers Extra headers
     * @return array Decoded response
     * @throws ShopPaymentException
     */
    private function request(string $method, string $path, mixed $body = null, array $headers = []): array
    {
        $curl = \curl_init($this->baseUrl() . $path);

        $requestHeaders = \array_merge([
            'Authorization: Bearer ' . $this->accessToken(),
            'Content-Type: application/json',
            'Accept: application/json',
        ], $headers);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ];

        $hasBody = $body !== null;
        if ($hasBody) $options[CURLOPT_POSTFIELDS] = \json_encode($body);

        \curl_setopt_array($curl, $options);

        $raw = \curl_exec($curl);
        $status = (int)\curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = \curl_error($curl);

        $callFailed = $raw === false;
        if ($callFailed) throw new ShopPaymentException('Could not reach PayPal: ' . $curlError);

        $response = \json_decode(json: (string)$raw, associative: true) ?? [];

        $callRejected = $status < 200 || $status >= 300;
        if ($callRejected) {
            \error_log("PayPal {$method} {$path} failed with status {$status}: " . (string)$raw);

            // The response body is carried on the exception as well as logged.
            // Callers must treat it as diagnostic and never show it to a
            // customer — but on an environment with no log access it is the
            // only way to find out what PayPal actually objected to.
            $detail = \mb_substr(string: (string)$raw, start: 0, length: 500);
            throw new ShopPaymentException("PayPal rejected the request ({$status}): {$detail}");
        }

        return $response;
    }
}
