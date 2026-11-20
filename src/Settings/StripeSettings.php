<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

/**
 * Stripe: secret key of the mode plus the secret of the webhook that the backend
 * registers (StripeWebhookView). The webhook data belongs to one key and mode and
 * is reset when either changes.
 */
final class StripeSettings implements ProviderSettingsInterface
{
    private const WEBHOOK = ['webhookSecret', 'endpointId', 'webhookIdentity', 'webhookOperation'];

    public static function provider(): string
    {
        return 'stripe';
    }

    public static function priority(): int
    {
        return 30;
    }

    public function switches(): array
    {
        return ['stripeEnabled' => false];
    }

    public function secrets(): array
    {
        return ['secretKey' => 'NW_PAYMENT_STRIPE_SECRET_KEY', 'webhookSecret' => 'NW_PAYMENT_STRIPE_WEBHOOK_SECRET'];
    }

    public function values(): array
    {
        return ['endpointId', 'webhookIdentity', 'webhookOperation'];
    }

    public function checkSecret(string $field, string $value, bool $testMode): void
    {
        if ('secretKey' === $field && '' !== $value && !preg_match($testMode ? '/^(sk|rk)_test_/' : '/^(sk|rk)_live_/', $value)) {
            throw new \InvalidArgumentException('Stripe-Schlüssel passt nicht zum Testmodus.');
        }
    }

    public function enabled(SettingsView $settings): bool
    {
        return $settings->switch('stripeEnabled') && '' !== $settings->secret('secretKey') && '' !== $settings->secret('webhookSecret');
    }

    public function standalone(): bool
    {
        return true;
    }

    public function missing(SettingsView $settings): array
    {
        try {
            // Both are read even when Stripe is off: a stored secret that cannot be read or
            // does not fit the mode needs attention before anyone switches Stripe on.
            $key = $settings->secret('secretKey');
            $webhook = $settings->secret('webhookSecret');

            return $settings->switch('stripeEnabled') && ('' === $key || '' === $webhook) ? ['Zahlung: Stripe-Schlüssel und Webhook-Secret ergänzen'] : [];
        } catch (\RuntimeException) {
            return ['Zahlung: Stripe-Schlüssel neu eingeben'];
        } catch (\InvalidArgumentException) {
            return ['Zahlung: Stripe-Schlüssel oder Testmodus prüfen'];
        }
    }

    public function apply(SettingsChange $change): array
    {
        $key = $change->submitted('secretKey');
        $webhook = $change->submitted('webhookSecret');
        // The mode applies to all online providers, so only a provider with a stored key
        // needs a new one.
        if ($change->modeChanged() && '' === $key && $change->stored('secretKey')) {
            throw new SettingsException('modeChangeNeedsKey', 'Beim Moduswechsel den passenden Stripe-Schlüssel neu eintragen.');
        }

        try {
            $this->checkSecret('secretKey', $key, $change->testMode());
        } catch (\InvalidArgumentException) {
            throw new SettingsException('keyModeMismatch', 'Stripe-Schlüssel passt nicht zum Testmodus.');
        }
        if ('' !== $webhook && !str_starts_with($webhook, 'whsec_')) {
            throw new SettingsException('invalidWebhookSecret', 'Ungültiges Webhook-Secret.');
        }
        $rotated = $change->modeChanged() || ('' !== $key && $change->storedSecret('secretKey') !== $key);
        // The old key is dropped, so nothing may still depend on it: open payments and
        // refunds of the old mode could no longer be read from Stripe.
        if ($rotated && $change->stored('secretKey')) {
            $change->assertNothingOpen('stripe', 'openPaymentsStripe', 'Stripe');
        }
        $update = [];
        if ($rotated) {
            foreach (['secretKey', ...self::WEBHOOK] as $field) {
                $update[$field] = '';
            }
        }
        if ('' !== $key) {
            $update['secretKey'] = $key;
        }
        if ('' !== $webhook) {
            $update['webhookSecret'] = $webhook;
        }
        $storedKey = $update['secretKey'] ?? ($change->current['secretKey'] ?? '');
        $storedWebhook = $update['webhookSecret'] ?? ($change->current['webhookSecret'] ?? '');
        if ($change->switch('stripeEnabled') && ('' === $storedKey || '' === $storedWebhook)) {
            throw new SettingsException('stripeIncomplete', 'Stripe zunächst ausgeschaltet speichern, dann Webhook anlegen und Stripe einschalten.');
        }

        return $update;
    }
}
