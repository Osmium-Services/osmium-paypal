<?php

declare(strict_types=1);

namespace Tests\Services\PayPal;

use Osmium\Services\PayPal\Models\PayPalStatusIcon;
use PHPUnit\Framework\TestCase;

foreach (['Client', 'Config', 'PaymentProvider', 'StatusIcon'] as $model) {
    require_once __DIR__ . "/../models/PayPal{$model}.php";
}

/**
 * The navbar card: only for the selected provider, green when live and ready, amber and crossed otherwise.
 */
final class PayPalStatusIconTest extends TestCase
{
    private string $originalDirectory;

    protected function setUp(): void
    {
        $this->originalDirectory = (string) \getcwd();
        \chdir(\sys_get_temp_dir()); // No config file here, so the defaults apply: off, sandbox mode
    }

    protected function tearDown(): void
    {
        \chdir($this->originalDirectory);
    }

    private function payload(string $provider): array
    {
        return ['osmium' => (object) ['config' => (object) ['checkout' => (object) ['paymentProvider' => $provider]]]];
    }

    public function test_no_icon_when_another_provider_is_selected(): void
    {
        $this->assertSame([], PayPalStatusIcon::icons($this->payload('none')));
    }

    public function test_unconfigured_selected_provider_is_amber_and_crossed(): void
    {
        $icon = PayPalStatusIcon::icons($this->payload('paypal'))[0];

        $this->assertSame('warning', $icon['color']);
        $this->assertTrue($icon['crossed']);
        $this->assertSame('settings/paypal/', $icon['path']);
    }

    public function test_ready_and_live_is_green(): void
    {
        $icon = PayPalStatusIcon::icon(ready: true, testMode: false);

        $this->assertSame('success', $icon['color']);
        $this->assertFalse($icon['crossed']);
    }
}
