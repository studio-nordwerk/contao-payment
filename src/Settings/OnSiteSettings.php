<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

use Nordwerk\PaymentBundle\OnSite\PayPalMe;

/**
 * Payment on site, in cash or with an optional PayPal.me link.
 */
final class OnSiteSettings implements ProviderSettingsInterface
{
    public static function provider(): string
    {
        return 'on_site';
    }

    public static function priority(): int
    {
        return 0;
    }

    public function switches(): array
    {
        return ['onSiteEnabled' => false];
    }

    public function secrets(): array
    {
        return [];
    }

    public function values(): array
    {
        return ['payPalMe'];
    }

    public function checkSecret(string $field, string $value, bool $testMode): void
    {
    }

    public function enabled(SettingsView $settings): bool
    {
        return $settings->switch('onSiteEnabled');
    }

    public function standalone(): bool
    {
        return false;
    }

    public function missing(SettingsView $settings): array
    {
        return [];
    }

    public function apply(SettingsChange $change): array
    {
        if (!$change->has('payPalMe')) {
            return [];
        }

        try {
            return ['payPalMe' => PayPalMe::normalize($change->submitted('payPalMe'))];
        } catch (\InvalidArgumentException) {
            throw new SettingsException('invalidPayPalMe', 'Bitte einen PayPal.me-Link wie paypal.me/IhrName eingeben.');
        }
    }
}
