<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

use Doctrine\DBAL\Connection;

final readonly class PaymentSettings
{
    public function __construct(
        private Connection $connection,
        private SecretCipher $cipher,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        $json = $this->connection->fetchOne('SELECT data FROM tl_nw_payment_settings WHERE id = 1');

        return \is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function enabled(string $provider): bool
    {
        $data = $this->stored();

        try {
            return match ($provider) {
                'bank_transfer' => (bool) ($data['bankEnabled'] ?? true),
                'stripe' => (bool) ($data['stripeEnabled'] ?? false) && '' !== $this->secretKey() && '' !== $this->webhookSecret(),
                default => false,
            };
        } catch (\InvalidArgumentException|\RuntimeException) {
            return false;
        }
    }

    public function testMode(): bool
    {
        $override = getenv('NW_PAYMENT_STRIPE_TEST_MODE');

        return false !== $override && '' !== $override ? '1' === $override : (bool) ($this->stored()['testMode'] ?? true);
    }

    public function secretKey(): string
    {
        $key = $this->secret('secretKey', 'NW_PAYMENT_STRIPE_SECRET_KEY');
        if ('' !== $key && !preg_match($this->testMode() ? '/^(sk|rk)_test_/' : '/^(sk|rk)_live_/', $key)) {
            throw new \InvalidArgumentException('Stripe-Schlüssel passt nicht zum Testmodus.');
        }

        return $key;
    }

    public function webhookSecret(): string
    {
        return $this->secret('webhookSecret', 'NW_PAYMENT_STRIPE_WEBHOOK_SECRET');
    }

    public function save(bool $bank, bool $stripe, bool $test, string $key, string $webhook): void
    {
        $data = $this->stored();
        $oldTest = (bool) ($data['testMode'] ?? true);
        if ($oldTest !== $test && ('' === $key || '' === $webhook)) {
            throw new \InvalidArgumentException('Beim Moduswechsel beide Schlüssel neu eintragen.');
        }
        if ('' !== $key && !preg_match($test ? '/^(sk|rk)_test_/' : '/^(sk|rk)_live_/', $key)) {
            throw new \InvalidArgumentException('Stripe-Schlüssel passt nicht zum Testmodus.');
        }
        if ('' !== $webhook && !str_starts_with($webhook, 'whsec_')) {
            throw new \InvalidArgumentException('Ungültiges Webhook-Secret.');
        }
        $data['bankEnabled'] = $bank;
        $data['stripeEnabled'] = $stripe;
        $data['testMode'] = $test;

        foreach (['secretKey' => $key, 'webhookSecret' => $webhook] as $field => $value) {
            if ('' !== $value) {
                $data[$field] = $this->cipher->encrypt($value);
            }
        }
        $this->persist($data);
    }

    public function saveWebhook(string $secret, string $endpointId): void
    {
        if (!str_starts_with($secret, 'whsec_')) {
            throw new \RuntimeException('Stripe hat kein Webhook-Secret zurückgegeben.');
        }
        $data = $this->stored();
        $data['webhookSecret'] = $this->cipher->encrypt($secret);
        $data['endpointId'] = $endpointId;
        $this->persist($data);
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        $data = $this->stored();

        try {
            if (($data['stripeEnabled'] ?? false) && !$this->enabled('stripe')) {
                return ['Zahlung: Stripe-Schlüssel und Webhook-Secret ergänzen'];
            }
            if (!$this->enabled('bank_transfer') && !$this->enabled('stripe')) {
                return ['Zahlung: mindestens einen Anbieter einrichten'];
            }
        } catch (\InvalidArgumentException|\RuntimeException) {
            return ['Zahlung: Stripe-Schlüssel oder Testmodus prüfen'];
        }

        return [];
    }

    private function secret(string $field, string $environment): string
    {
        $override = getenv($environment);
        if (false !== $override && '' !== $override) {
            return $override;
        }
        $encrypted = $this->stored()[$field] ?? '';

        return \is_string($encrypted) && '' !== $encrypted ? $this->cipher->decrypt($encrypted) : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persist(array $data): void
    {
        $this->connection->executeStatement('INSERT INTO tl_nw_payment_settings (id, tstamp, data) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE tstamp = VALUES(tstamp), data = VALUES(data)', [time(), json_encode($data, JSON_THROW_ON_ERROR)]);
    }
}
