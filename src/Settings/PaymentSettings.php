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
        $this->connection->transactional(
            function () use ($bank, $stripe, $test, $key, $webhook): void {
                $data = $this->lockedData();
                $oldTest = (bool) ($data['testMode'] ?? true);
                if ($oldTest !== $test && '' === $key) {
                    throw new \InvalidArgumentException('Beim Moduswechsel den passenden Stripe-Schlüssel neu eintragen.');
                }
                if ('' !== $key && !preg_match($test ? '/^(sk|rk)_test_/' : '/^(sk|rk)_live_/', $key)) {
                    throw new \InvalidArgumentException('Stripe-Schlüssel passt nicht zum Testmodus.');
                }
                if ('' !== $webhook && !str_starts_with($webhook, 'whsec_')) {
                    throw new \InvalidArgumentException('Ungültiges Webhook-Secret.');
                }

                try {
                    $oldKey = isset($data['secretKey']) ? $this->cipher->decrypt((string) $data['secretKey']) : '';
                } catch (\RuntimeException) {
                    $oldKey = '';
                }
                if ($oldTest !== $test || ('' !== $key && $oldKey !== $key)) {
                    unset($data['secretKey'], $data['webhookSecret'], $data['endpointId'], $data['webhookIdentity'], $data['webhookOperation']);
                }
                $data['bankEnabled'] = $bank;
                $data['stripeEnabled'] = $stripe;
                $data['testMode'] = $test;

                foreach (['secretKey' => $key, 'webhookSecret' => $webhook] as $field => $value) {
                    if ('' !== $value) {
                        $data[$field] = $this->cipher->encrypt($value);
                    }
                }
                if ($stripe && ('' === ($data['secretKey'] ?? '') || '' === ($data['webhookSecret'] ?? ''))) {
                    throw new \InvalidArgumentException('Stripe zunächst ausgeschaltet speichern, dann Webhook anlegen und Stripe einschalten.');
                }
                $this->persist($data);
            },
        );
    }

    public function prepareWebhook(string $url): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Webhook registration requires a committed operation.');
        }
        $this->connection->transactional(
            function () use ($url): void {
                $data = $this->lockedData();
                $identity = hash('sha256', $url.':'.($this->testMode() ? 'test' : 'live').':'.$this->secretKey());
                if (isset($data['webhookIdentity']) && $data['webhookIdentity'] !== $identity) {
                    throw new \InvalidArgumentException('Webhook-Adresse oder Stripe-Konto geändert. Vorhandenen Endpunkt vor dem Wechsel prüfen.');
                }
                $data['webhookIdentity'] = $identity;
                $data['webhookOperation'] ??= bin2hex(random_bytes(32));
                $this->persist($data);
            },
        );
    }

    /**
     * @param callable(array<string, mixed>): void $register
     */
    public function withWebhookLock(callable $register): void
    {
        $this->connection->transactional(
            function () use ($register): void {
                $register($this->lockedData());
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lockedData(): array
    {
        $this->connection->executeStatement("INSERT IGNORE INTO tl_nw_payment_settings (id, tstamp, data) VALUES (1, ?, '{}')", [time()]);
        $json = $this->connection->fetchOne('SELECT data FROM tl_nw_payment_settings WHERE id = 1 FOR UPDATE');

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
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
            $this->secretKey();
            $this->webhookSecret();
            if (($data['stripeEnabled'] ?? false) && !$this->enabled('stripe')) {
                return ['Zahlung: Stripe-Schlüssel und Webhook-Secret ergänzen'];
            }
            if (!$this->enabled('bank_transfer') && !$this->enabled('stripe')) {
                return ['Zahlung: mindestens einen Anbieter einrichten'];
            }
        } catch (\RuntimeException) {
            return ['Zahlung: Stripe-Schlüssel neu eingeben'];
        } catch (\InvalidArgumentException) {
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
