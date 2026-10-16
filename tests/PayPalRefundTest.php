<?php

declare(strict_types=1);

namespace Tests\Services\PayPal;

use Osmium\Services\PayPal\Models\PayPalRefund;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/PayPalRefund.php';

/**
 * A partial refund must not mark the whole order refunded.
 */
final class PayPalRefundTest extends TestCase
{
    private function order(array $refunds): array
    {
        return ['purchase_units' => [['payments' => ['refunds' => $refunds]]]];
    }

    public function test_refunds_are_added_up_across_the_order(): void
    {
        $order = $this->order([
            ['status' => 'COMPLETED', 'amount' => ['value' => '10.00']],
            ['status' => 'COMPLETED', 'amount' => ['value' => '5.50']],
        ]);

        $this->assertSame(15.5, PayPalRefund::refundedTotal($order));
    }

    public function test_refunds_that_failed_or_were_cancelled_are_not_counted(): void
    {
        $order = $this->order([
            ['status' => 'COMPLETED', 'amount' => ['value' => '10.00']],
            ['status' => 'FAILED', 'amount' => ['value' => '90.00']],
            ['status' => 'CANCELLED', 'amount' => ['value' => '90.00']],
        ]);

        $this->assertSame(10.0, PayPalRefund::refundedTotal($order));
    }

    public function test_an_order_with_no_refunds_totals_nothing(): void
    {
        $this->assertSame(0.0, PayPalRefund::refundedTotal([]));
        $this->assertSame(0.0, PayPalRefund::refundedTotal($this->order([])));
    }

    public function test_only_a_total_that_reaches_the_order_is_a_full_refund(): void
    {
        $this->assertTrue(PayPalRefund::isFull(refundedTotal: 60.0, orderTotal: 60.0));
        $this->assertTrue(PayPalRefund::isFull(refundedTotal: 59.996, orderTotal: 60.0), 'within half a penny');
        $this->assertFalse(PayPalRefund::isFull(refundedTotal: 59.0, orderTotal: 60.0));
        $this->assertFalse(PayPalRefund::isFull(refundedTotal: 0.0, orderTotal: 0.0));
    }
}
