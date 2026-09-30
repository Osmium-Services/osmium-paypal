<?php

declare(strict_types=1);

namespace Osmium\Services\PayPal\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\PayPal\Models\PayPalConfig;

/**
 * PayPal settings controller - switch, mode and credentials.
 *
 * Routes:
 *   - index() → /admin/settings/paypal/
 *
 * Choosing PayPal as the active checkout provider happens on the core
 * Settings > Payments page; this page only holds PayPal's own settings.
 */
class PayPalController extends AdminController
{
    private const FLASH_KEY = 'paypal_flash';

    private const KEY_ALPHABET = '/^[A-Za-z0-9_-]+$/';

    /**
     * Post field => [config key, label, minimum length]. Every one is a
     * credential: a blank submission keeps the stored value.
     */
    private const SECRET_FIELDS = [
        'sandbox_client_id' => ['sandboxClientId', 'Sandbox Client ID', 40],
        'sandbox_secret' => ['sandboxSecret', 'Sandbox Secret', 40],
        'live_client_id' => ['liveClientId', 'Live Client ID', 40],
        'live_secret' => ['liveSecret', 'Live Secret', 40],
        'webhook_id' => ['webhookId', 'Webhook ID', 10],
    ];

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->handleSubmit();

        $config = (array) PayPalConfig::get();

        $this->data['admin']['paypal'] = [
            'enabled' => (bool) $config['enabled'],
            'mode' => $config['mode'],
            'brandName' => $config['brandName'],
            'has' => \array_map(
                fn(array $field) => $config[$field[0]] !== '',
                self::SECRET_FIELDS,
            ),
            'webhookUrl' => $this->webhookUrl(),
        ];
        $this->data['admin']['flash'] = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);

        $this->setView('paypal/index.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) $this->flashAndRedirect('danger', 'Invalid form submission. Please try again.');

        $current = (array) PayPalConfig::get();
        $values = $current;

        foreach (self::SECRET_FIELDS as $postField => [$configKey, $label, $minLength]) {
            $posted = \trim((string) ($_POST[$postField] ?? ''));

            $keepCurrent = $posted === '';
            if ($keepCurrent) continue;

            $error = $this->shapeError(label: $label, value: $posted, minLength: $minLength);
            if ($error !== null) $this->flashAndRedirect('danger', $error);

            $values[$configKey] = $posted;
        }

        $values['enabled'] = isset($_POST['enabled']);
        $values['mode'] = ($_POST['mode'] ?? '') === 'live' ? 'live' : 'sandbox';
        $values['brandName'] = \mb_substr(string: \trim((string) ($_POST['brand_name'] ?? '')), start: 0, length: 127);

        PayPalConfig::save($values);

        $this->admin->model->changelog->log(
            description: 'Updated PayPal settings',
            recordType: 'settings',
        );

        $this->flashAndRedirect('success', 'Settings saved successfully!');
    }

    /**
     * Catches a credential that is the wrong *shape* - truncated, masked, or
     * carrying stray characters. A live client id once went in as
     * "AXMjbL02Bv0OiYxN-pMl9..." and the only symptom was "payment unavailable"
     * on the customer's checkout.
     */
    private function shapeError(string $label, string $value, int $minLength): ?string
    {
        $hasStrayCharacters = \preg_match(self::KEY_ALPHABET, $value) !== 1;
        if ($hasStrayCharacters) {
            return "{$label} contains characters that no real PayPal credential has - usually a sign it was copied "
                . 'from an abbreviated (ending "...") or masked display. Copy the full value using the PayPal dashboard\'s copy button.';
        }

        $tooShort = \strlen($value) < $minLength;
        if ($tooShort) {
            return "{$label} is only " . \strlen($value) . ' characters, which is too short to be a real one. '
                . 'It looks truncated - copy the full value from the PayPal dashboard.';
        }

        return null;
    }

    private function webhookUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return "{$scheme}://{$_SERVER['HTTP_HOST']}/api/checkout-paypal-webhook";
    }

    private function flashAndRedirect(string $type, string $text): void
    {
        $_SESSION[self::FLASH_KEY] = ['type' => $type, 'text' => $text];
        $this->redirect('settings/paypal/');
    }
}
