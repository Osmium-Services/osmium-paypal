<?php

declare(strict_types=1);

namespace Tests\Services\PayPal;

use Osmium\Services\PayPal\Models\PayPalPaymentProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/PayPalPaymentProvider.php';

/**
 * The order records how the customer paid, read from the capture's payment_source.
 */
final class PayPalMethodLabelTest extends TestCase
{
    public function test_a_paypal_account_payment_says_so(): void
    {
        $label = PayPalPaymentProvider::methodLabel(['paypal' => ['email_address' => 'a@b.c', 'account_status' => 'VERIFIED']]);

        $this->assertSame('PayPal account', $label);
    }

    public function test_a_card_shows_its_brand_and_last_digits(): void
    {
        $label = PayPalPaymentProvider::methodLabel(['card' => ['brand' => 'VISA', 'last_digits' => '7607', 'type' => 'CREDIT']]);

        $this->assertSame('Card via PayPal (Visa ••7607)', $label);
    }

    public function test_a_card_without_details_is_still_a_card(): void
    {
        $this->assertSame('Card via PayPal', PayPalPaymentProvider::methodLabel(['card' => []]));
        $this->assertSame('Card via PayPal', PayPalPaymentProvider::methodLabel(['card' => ['brand' => 'VISA']]));
    }

    public function test_anything_else_is_left_blank(): void
    {
        $this->assertNull(PayPalPaymentProvider::methodLabel([]));
        $this->assertNull(PayPalPaymentProvider::methodLabel(['apple_pay' => ['name' => 'x']]));
    }

    public function test_card_digits_are_digits_only(): void
    {
        $label = PayPalPaymentProvider::methodLabel(['card' => ['brand' => 'MASTERCARD', 'last_digits' => '12<b>34']]);

        $this->assertSame('Card via PayPal (Mastercard ••1234)', $label);
    }
}
