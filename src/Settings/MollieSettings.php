<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

/**
 * Mollie: one API key per mode (test_… or live_…). Mollie needs no registered
 * webhook; the payment names its webhook URL itself.
 */
final class MollieSettings implements ProviderSettingsInterface
{
    public static function provider(): string
    {
        return 'mollie';
    }

    public static function priority(): int
    {
        return 20;
    }

    public function switches(): array
    {
        return ['mollieEnabled' => false];
    }

    public function secrets(): array
    {
        return ['mollieApiKey' => 'NW_PAYMENT_MOLLIE_API_KEY'];
    }

    public function values(): array
    {
        return [];
    }

    public function checkSecret(string $field, string $value, bool $testMode): void
    {
        if ('mollieApiKey' === $field && '' !== $value && !str_starts_with($value, $testMode ? 'test_' : 'live_')) {
            throw new \InvalidArgumentException('Mollie-API-Key passt nicht zum Testmodus.');
        }
    }

    public function enabled(SettingsView $settings): bool
    {
        return $settings->switch('mollieEnabled') && '' !== $settings->secret('mollieApiKey');
    }

    public function standalone(): bool
    {
        return true;
    }

    public function missing(SettingsView $settings): array
    {
        try {
            // Read even when Mollie is off, like Stripe.
            $key = $settings->secret('mollieApiKey');

            return $settings->switch('mollieEnabled') && '' === $key ? ['Zahlung: Mollie-API-Key ergänzen'] : [];
        } catch (\RuntimeException) {
            return ['Zahlung: Mollie-API-Key neu eingeben'];
        } catch (\InvalidArgumentException) {
            return ['Zahlung: Mollie-API-Key oder Testmodus prüfen'];
        }
    }

    public function apply(SettingsChange $change): array
    {
        $key = $change->submitted('mollieApiKey');
        if ($change->modeChanged() && '' === $key && $change->stored('mollieApiKey')) {
            throw new SettingsException('modeChangeNeedsMollieKey', 'Beim Moduswechsel den passenden Mollie-API-Key neu eintragen.');
        }

        try {
            $this->checkSecret('mollieApiKey', $key, $change->testMode());
        } catch (\InvalidArgumentException) {
            throw new SettingsException('mollieKeyModeMismatch', 'Mollie-API-Key passt nicht zum Testmodus.');
        }
        // Open payments and refunds of the old key could no longer be read from Mollie.
        if ($change->stored('mollieApiKey') && ($change->modeChanged() || ('' !== $key && $change->storedSecret('mollieApiKey') !== $key))) {
            $change->assertNothingOpen('mollie', 'openPaymentsMollie', 'Mollie');
        }
        $update = [];
        if ($change->modeChanged()) {
            $update['mollieApiKey'] = '';
        }
        if ('' !== $key) {
            $update['mollieApiKey'] = $key;
        }
        if ($change->switch('mollieEnabled') && '' === ($update['mollieApiKey'] ?? ($change->current['mollieApiKey'] ?? ''))) {
            throw new SettingsException('mollieIncomplete', 'Mollie lässt sich nur mit gespeichertem API-Key einschalten.');
        }

        return $update;
    }
}
