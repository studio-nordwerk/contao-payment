<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

/**
 * Advance payment by bank transfer; the bank account belongs to the consumer
 * (shop, seminar), so only the switch lives here.
 */
final class BankTransferSettings implements ProviderSettingsInterface
{
    public static function provider(): string
    {
        return 'bank_transfer';
    }

    public static function priority(): int
    {
        return 40;
    }

    public function switches(): array
    {
        return ['bankEnabled' => true];
    }

    public function secrets(): array
    {
        return [];
    }

    public function values(): array
    {
        return [];
    }

    public function checkSecret(string $field, string $value, bool $testMode): void
    {
    }

    public function enabled(SettingsView $settings): bool
    {
        return $settings->switch('bankEnabled');
    }

    public function standalone(): bool
    {
        return true;
    }

    public function missing(SettingsView $settings): array
    {
        return [];
    }

    public function apply(SettingsChange $change): array
    {
        return [];
    }
}
