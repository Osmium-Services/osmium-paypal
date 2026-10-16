<?php

declare(strict_types=1);

namespace Osmium\Services\PayPal\Models;

/**
 * Whether the refunds on a PayPal order add up to the whole payment. Pure.
 *
 * PAYMENT.CAPTURE.REFUNDED is sent for each refund, part or full, and carries only that refund's amount, so the
 * running total comes from the order read back from PayPal.
 */
class PayPalRefund
{
    private const COUNTED_STATUSES = ['COMPLETED', 'PENDING'];

    /** Total of the refunds on a PayPal order (GET /v2/checkout/orders/{id}). */
    public static function refundedTotal(array $paypalOrder): float
    {
        $total = 0.0;

        foreach ($paypalOrder['purchase_units'] ?? [] as $unit) {
            foreach ($unit['payments']['refunds'] ?? [] as $refund) {
                $status = \strtoupper((string)($refund['status'] ?? ''));
                $counts = \in_array($status, self::COUNTED_STATUSES, true);
                if ($counts) $total += (float)($refund['amount']['value'] ?? 0);
            }
        }

        return $total;
    }

    /** True only when the refunded total reaches the order total; a zero total is never a full refund. */
    public static function isFull(float $refundedTotal, float $orderTotal): bool
    {
        return $orderTotal > 0 && $refundedTotal >= $orderTotal - 0.005;
    }
}
