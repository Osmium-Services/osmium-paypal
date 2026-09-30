<?php

use Osmium\Services\PayPal\Models\PayPalConfig;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Services\ShopOrderMailer;
use Osmium\Modules\Checkout\Services\ShopOrderService;
use Osmium\Modules\Checkout\Services\ShopTotalsService;

require_once __DIR__ . '/../../../modules/checkout/services/ShopTotalsService.php';
require_once __DIR__ . '/../../../modules/checkout/services/CheckoutBasketService.php';
require_once __DIR__ . '/../../../modules/checkout/services/ShopOrderException.php';
require_once __DIR__ . '/../../../modules/checkout/services/ShopOrderService.php';
require_once __DIR__ . '/../../../modules/checkout/services/ShopOrderMailer.php';
require_once __DIR__ . '/../../../modules/checkout/services/ShopPaymentException.php';

/**
 * PayPal webhook.
 *
 * The safety net. If a customer's browser dies between approving a payment and
 * our capture call returning, the browser flow records nothing — and that is
 * money taken with no order behind it. This endpoint is how that order still
 * gets marked paid.
 *
 * It is unauthenticated by nature, so the signature check is the only thing
 * standing between PayPal and anybody who can POST JSON at us. An event that
 * fails verification is discarded, never treated as probably genuine.
 *
 * Query strings are stripped by RedirectHandler, so nothing here may depend on
 * them — everything comes from the POST body.
 */

$rawBody = \file_get_contents('php://input');

$paypal = PayPalConfig::client();

if (!$paypal->isEnabled()) {
    \http_response_code(503);
    exit;
}

$headers = \function_exists('getallheaders') ? \getallheaders() : [];
$normalisedHeaders = [];
foreach ($headers as $name => $value) $normalisedHeaders[\strtoupper($name)] = $value;

$signatureValid = $paypal->verifyWebhookSignature(headers: $normalisedHeaders, rawBody: (string)$rawBody);

if (!$signatureValid) {
    \error_log('PayPal webhook rejected: signature did not verify');
    \http_response_code(400);
    exit;
}

$event = \json_decode(json: (string)$rawBody, associative: true) ?? [];
$eventType = (string)($event['event_type'] ?? '');
$resource = $event['resource'] ?? [];

$sqlDir = 'app/modules/checkout/models/sql/';

$basketService = new CheckoutBasketService(dataSource: $this->osmium->dataSource, sqlDir: $sqlDir);
$orderService = new ShopOrderService(
    dataSource: $this->osmium->dataSource,
    sqlDir: $sqlDir,
    basket: $basketService,
    totals: ShopTotalsService::fromConfig($this->osmium->config->checkout),
);

/**
 * Find our order from a capture resource.
 *
 * The capture carries the PayPal order id in supplementary_data, and our own
 * order_ref in custom_id — we set both, so either will do, and having two ways
 * in matters when one of them is missing from a particular event shape.
 */
$findOrder = function (array $resource) use ($orderService): ?array {
    $paypalOrderId = (string)($resource['supplementary_data']['related_ids']['order_id'] ?? '');

    if ($paypalOrderId !== '') {
        $order = $orderService->findByPaymentReference($paypalOrderId);
        if ($order) return $order;
    }

    return null;
};

$order = $findOrder($resource);

if (!$order) {
    \error_log('PayPal webhook ' . $eventType . ' could not be matched to an order');
    \http_response_code(200); // Acknowledged: retrying will not help, and an unacknowledged event is retried for days
    exit;
}

$orderId = (int)$order['id'];

switch ($eventType) {
    case 'PAYMENT.CAPTURE.COMPLETED':
        $invoiceNumber = $orderService->markPaid(
            orderId: $orderId,
            captureRef: (string)($resource['id'] ?? ''),
            payerEmail: $resource['payer']['email_address'] ?? null,
        ); // Idempotent: if the browser already confirmed this, it does nothing

        $wasAlreadyPaid = $invoiceNumber === null;
        if ($wasAlreadyPaid) break;

        \error_log("PayPal webhook completed order {$order['order_ref']} that the browser did not confirm"); // Worth knowing about: it means a customer saw an error on a payment that actually succeeded

        $mailer = new ShopOrderMailer($this->osmium->config);
        $mailer->sendOrderConfirmation($orderService->getOrder($orderId), $invoiceNumber);
        break;

    case 'PAYMENT.CAPTURE.DENIED':
        $orderService->markStatus(orderId: $orderId, status: 'failed');
        break;

    case 'PAYMENT.CAPTURE.REFUNDED':
        $orderService->markStatus(orderId: $orderId, status: 'refunded'); // Recorded, not executed — refunds are issued in PayPal
        break;

    default:
        break; // Acknowledged and ignored
}

\http_response_code(200);
exit;
