<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

/**
 * The settings of one payment provider in the settings record
 * tl_nw_payment_settings: its columns, when it takes payments and what a change
 * must respect. Registered by autoconfiguration (tag
 * nordwerk.payment.provider_settings); the provider id is the one of its
 * Domain\PaymentProviderInterface. The columns themselves are DCA fields like any
 * other (contao/dca/tl_nw_payment_settings.php).
 */
interface ProviderSettingsInterface
{
    public static function provider(): string;

    /**
     * Order of the setup messages; the same as in the checkout.
     */
    public static function priority(): int;

    /**
     * @return array<string, bool> switch columns (char(1)) and their value without a record
     */
    public function switches(): array;

    /**
     * Write-only columns, stored encrypted.
     *
     * @return array<string, string> column => environment variable that overrides it ('' for none)
     */
    public function secrets(): array;

    /**
     * @return list<string> other text columns
     */
    public function values(): array;

    /**
     * Rejects a secret that does not fit the mode.
     *
     * @throws \InvalidArgumentException
     */
    public function checkSecret(string $field, string $value, bool $testMode): void;

    /**
     * Whether the provider takes payments. May throw if a secret cannot be read or
     * does not fit the mode; the provider then counts as disabled.
     */
    public function enabled(SettingsView $settings): bool;

    /**
     * Whether the provider can be the only payment method; payment on site is only
     * offered for pickup.
     */
    public function standalone(): bool;

    /**
     * @return list<string> what is missing before the provider works (German, for the setup check)
     */
    public function missing(SettingsView $settings): array;

    /**
     * Validates a change and returns the values of its own columns to store. Secrets
     * are returned in plain text (or '' to remove them); the caller encrypts them.
     *
     * @return array<string, mixed>
     *
     * @throws SettingsException
     */
    public function apply(SettingsChange $change): array;
}
