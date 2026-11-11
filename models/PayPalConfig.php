<?php

declare(strict_types=1);

namespace Osmium\Services\PayPal\Models;

/**
 * PayPal configuration.
 *
 * File-based config (app/config/services/paypal.json.php), matching the other
 * services. Credentials are secrets: the settings page never echoes them back, and a
 * blank submission keeps the stored value.
 */
class PayPalConfig
{
    /**
     * Payment types this service can take, as id => label. The site picks which of them it
     * shows customers (acceptedMethods); ids match the shop service's PaymentProviders::METHODS.
     */
    public const SUPPORTED_METHODS = ['paypal' => 'PayPal', 'visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express'];

    private static ?object $config = null;
    private static string $configPath = 'app/config/services/paypal.json.php';

    public static function get(): object
    {
        $configLoaded = self::$config !== null;
        if ($configLoaded) return self::$config;

        $configExists = \file_exists(self::$configPath);
        if (!$configExists) {
            self::$config = self::defaults();
            return self::$config;
        }

        $content = \file_get_contents(self::$configPath);
        $jsonStart = \strpos(haystack: $content, needle: '{');

        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) {
            self::$config = self::defaults();
            return self::$config;
        }

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart));
        $stored = (array) ($decoded->paypal ?? []);

        self::$config = (object) ($stored + (array) self::defaults());

        return self::$config;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function save(array $values): void
    {
        $dir = \dirname(self::$configPath);
        $dirExists = \is_dir($dir);
        if (!$dirExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $json = \json_encode(
            value: ['paypal' => $values],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::$configPath, "<?php exit(); ?>\n" . $json . "\n");
        self::clearCache();
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    public static function client(): PayPalClient
    {
        return new PayPalClient(self::get());
    }

    /**
     * The payment types the site accepts through this service, in SUPPORTED_METHODS order.
     *
     * @return string[]
     */
    public static function acceptedMethods(): array
    {
        $chosen = (array) (self::get()->acceptedMethods ?? []);

        return \array_values(\array_intersect(\array_keys(self::SUPPORTED_METHODS), $chosen));
    }

    private static function defaults(): object
    {
        return (object) [
            'enabled' => false,
            'acceptedMethods' => ['paypal', 'visa', 'mastercard', 'amex'],
            'mode' => 'sandbox',
            'sandboxClientId' => '',
            'sandboxSecret' => '',
            'liveClientId' => '',
            'liveSecret' => '',
            'webhookId' => '',
            'brandName' => '',
        ];
    }
}
