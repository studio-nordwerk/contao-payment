<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

use Doctrine\DBAL\Connection;
use Nordwerk\PaymentBundle\OnSite\PayPalMe;

/**
 * Reads and writes the one settings record tl_nw_payment_settings (id 1). The
 * backend form (DC_Table) and the programmatic methods below share
 * applyChanges(), so both enforce the same rules.
 */
final readonly class PaymentSettings
{
    /**
     * Switches (char(1) columns) and their value without a record.
     */
    public const SWITCHES = ['bankEnabled' => true, 'stripeEnabled' => false, 'testMode' => true, 'onSiteEnabled' => false];

    /**
     * Text columns. Secrets are stored encrypted; empty values are left out of stored().
     */
    public const VALUES = ['secretKey', 'webhookSecret', 'endpointId', 'webhookIdentity', 'webhookOperation', 'payPalMe'];

    /**
     * Belongs to one Stripe key and mode; reset when either changes.
     */
    private const WEBHOOK = ['webhookSecret', 'endpointId', 'webhookIdentity', 'webhookOperation'];

    public function __construct(
        private Connection $connection,
        private SecretCipher $cipher,
    ) {
    }

    /**
     * Settings with the keys of the former JSON format: switches as booleans, other
     * values as strings (secrets still encrypted), empty values omitted.
     *
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_nw_payment_settings WHERE id = 1');

        return self::fromRow(false === $row ? [] : $row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function fromRow(array $row): array
    {
        $data = [];

        foreach (self::SWITCHES as $field => $default) {
            $data[$field] = \array_key_exists($field, $row) ? '1' === (string) $row[$field] : $default;
        }

        foreach (self::VALUES as $field) {
            $value = (string) ($row[$field] ?? '');
            if ('' !== $value) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    public function enabled(string $provider): bool
    {
        $data = $this->stored();

        try {
            return match ($provider) {
                'bank_transfer' => (bool) $data['bankEnabled'],
                'on_site' => (bool) $data['onSiteEnabled'],
                'stripe' => (bool) $data['stripeEnabled'] && '' !== $this->secretKey() && '' !== $this->webhookSecret(),
                default => false,
            };
        } catch (\InvalidArgumentException|\RuntimeException) {
            return false;
        }
    }

    /**
     * PayPal.me name for payments on site, '' without one.
     */
    public function payPalMe(): string
    {
        try {
            return PayPalMe::normalize((string) ($this->stored()['payPalMe'] ?? ''));
        } catch (\InvalidArgumentException) {
            return '';
        }
    }

    public function saveOnSite(bool $enabled, string $payPalMe): void
    {
        $name = PayPalMe::normalize($payPalMe);
        $this->connection->transactional(
            function () use ($enabled, $name): void {
                $this->lockedRow();
                $this->persist(['onSiteEnabled' => $enabled ? '1' : '', 'payPalMe' => $name]);
            },
        );
    }

    public function testMode(): bool
    {
        $override = getenv('NW_PAYMENT_STRIPE_TEST_MODE');

        return false !== $override && '' !== $override ? '1' === $override : (bool) $this->stored()['testMode'];
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

    /**
     * Empty $key or $webhook keeps the stored secret.
     */
    public function save(bool $bank, bool $stripe, bool $test, string $key, string $webhook): void
    {
        $this->connection->transactional(
            function () use ($bank, $stripe, $test, $key, $webhook): void {
                $changes = ['bankEnabled' => $bank ? '1' : '', 'stripeEnabled' => $stripe ? '1' : '', 'testMode' => $test ? '1' : ''];
                if ('' !== $key) {
                    $changes['secretKey'] = $key;
                }
                if ('' !== $webhook) {
                    $changes['webhookSecret'] = $webhook;
                }
                $this->persist($this->applyChanges($this->lockedRow(), $changes));
            },
        );
    }

    /**
     * Validates submitted changes against the current record and returns the column
     * values to store: secrets encrypted, webhook data reset after a change of key or
     * mode. Plaintext secrets in $changes are replaced.
     *
     * @param array<string, mixed> $row     current database record (may be empty)
     * @param array<string, mixed> $changes changed columns; switches as '1'/'', secrets as plaintext
     *
     * @return array<string, mixed>
     */
    public function applyChanges(array $row, array $changes): array
    {
        $current = self::fromRow($row);
        $switch = static fn (string $field): bool => \array_key_exists($field, $changes) ? '1' === (string) $changes[$field] : (bool) $current[$field];
        $test = $switch('testMode');
        $key = trim((string) ($changes['secretKey'] ?? ''));
        $webhook = trim((string) ($changes['webhookSecret'] ?? ''));
        if ($current['testMode'] !== $test && '' === $key) {
            throw new SettingsException('modeChangeNeedsKey', 'Beim Moduswechsel den passenden Stripe-Schlüssel neu eintragen.');
        }
        if ('' !== $key && !preg_match($test ? '/^(sk|rk)_test_/' : '/^(sk|rk)_live_/', $key)) {
            throw new SettingsException('keyModeMismatch', 'Stripe-Schlüssel passt nicht zum Testmodus.');
        }
        if ('' !== $webhook && !str_starts_with($webhook, 'whsec_')) {
            throw new SettingsException('invalidWebhookSecret', 'Ungültiges Webhook-Secret.');
        }

        try {
            $oldKey = isset($current['secretKey']) ? $this->cipher->decrypt((string) $current['secretKey']) : '';
        } catch (\RuntimeException) {
            $oldKey = '';
        }
        $update = $changes;
        unset($update['secretKey'], $update['webhookSecret']);

        foreach (array_keys(self::SWITCHES) as $field) {
            if (\array_key_exists($field, $update)) {
                $update[$field] = $switch($field) ? '1' : '';
            }
        }
        if ($current['testMode'] !== $test || ('' !== $key && $oldKey !== $key)) {
            foreach (['secretKey', ...self::WEBHOOK] as $field) {
                $update[$field] = '';
            }
        }
        if ('' !== $key) {
            $update['secretKey'] = $this->cipher->encrypt($key);
        }
        if ('' !== $webhook) {
            $update['webhookSecret'] = $this->cipher->encrypt($webhook);
        }
        $storedKey = $update['secretKey'] ?? ($current['secretKey'] ?? '');
        $storedWebhook = $update['webhookSecret'] ?? ($current['webhookSecret'] ?? '');
        if ($switch('stripeEnabled') && ('' === $storedKey || '' === $storedWebhook)) {
            throw new SettingsException('stripeIncomplete', 'Stripe zunächst ausgeschaltet speichern, dann Webhook anlegen und Stripe einschalten.');
        }

        return $update;
    }

    public function prepareWebhook(string $url): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Webhook registration requires a committed operation.');
        }
        $this->connection->transactional(
            function () use ($url): void {
                $data = self::fromRow($this->lockedRow());
                $identity = hash('sha256', $url.':'.($this->testMode() ? 'test' : 'live').':'.$this->secretKey());
                if (isset($data['webhookIdentity']) && $data['webhookIdentity'] !== $identity) {
                    throw new \InvalidArgumentException('Webhook-Adresse oder Stripe-Konto geändert. Vorhandenen Endpunkt vor dem Wechsel prüfen.');
                }
                $this->persist(['webhookIdentity' => $identity, 'webhookOperation' => $data['webhookOperation'] ?? bin2hex(random_bytes(32))]);
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
                $register(self::fromRow($this->lockedRow()));
            },
        );
    }

    public function saveWebhook(string $secret, string $endpointId): void
    {
        if (!str_starts_with($secret, 'whsec_')) {
            throw new \RuntimeException('Stripe hat kein Webhook-Secret zurückgegeben.');
        }
        $this->persist(['webhookSecret' => $this->cipher->encrypt($secret), 'endpointId' => $endpointId]);
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
            if ($data['stripeEnabled'] && !$this->enabled('stripe')) {
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

    /**
     * Creates the record on first use; its column defaults match self::SWITCHES.
     */
    public function ensureRecord(): void
    {
        $this->connection->executeStatement('INSERT IGNORE INTO tl_nw_payment_settings (id, tstamp) VALUES (1, ?)', [time()]);
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
     * @return array<string, mixed>
     */
    private function lockedRow(): array
    {
        $this->ensureRecord();

        return $this->connection->fetchAssociative('SELECT * FROM tl_nw_payment_settings WHERE id = 1 FOR UPDATE') ?: [];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function persist(array $values): void
    {
        $this->ensureRecord();
        $this->connection->update('tl_nw_payment_settings', ['tstamp' => time(), ...$values], ['id' => 1]);
    }
}
