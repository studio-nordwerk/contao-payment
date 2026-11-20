<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

use Doctrine\DBAL\Connection;
use Nordwerk\PaymentBundle\OnSite\PayPalMe;

/**
 * Reads and writes the one settings record tl_nw_payment_settings (id 1). The backend
 * form (DC_Table) and the programmatic methods below share applyChanges(), so both
 * enforce the same rules. What belongs to one provider (its columns, when it takes
 * payments, the rules of a change) is in its ProviderSettingsInterface section; here
 * only the mode, the storage and the encryption.
 */
final readonly class PaymentSettings
{
    /**
     * Switches and text columns of the former JSON format, for
     * SettingsColumnsMigration only. Current columns come from the provider sections.
     */
    public const SWITCHES = ['bankEnabled' => true, 'stripeEnabled' => false, 'testMode' => true, 'onSiteEnabled' => false, 'mollieEnabled' => false];

    public const VALUES = ['secretKey', 'webhookSecret', 'endpointId', 'webhookIdentity', 'webhookOperation', 'payPalMe', 'mollieApiKey'];

    /**
     * @var array<string, ProviderSettingsInterface> provider id => section, in checkout order
     */
    private array $sections;

    /**
     * @param iterable<ProviderSettingsInterface>|null $sections null: the built-in providers
     */
    public function __construct(
        private Connection $connection,
        private SecretCipher $cipher,
        iterable|null $sections = null,
    ) {
        $this->sections = self::index($sections ?? self::builtIn());
    }

    /**
     * Settings in the stored() format for a record of the built-in providers.
     *
     * @deprecated use stored()
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function fromRow(array $row): array
    {
        return self::read(self::index(self::builtIn()), $row);
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

        return self::read($this->sections, false === $row ? [] : $row);
    }

    /**
     * @param array<string, ProviderSettingsInterface> $sections
     * @param array<string, mixed>                     $row
     *
     * @return array<string, mixed>
     */
    private static function read(array $sections, array $row): array
    {
        $switches = ['testMode' => true];
        $values = [];

        foreach ($sections as $section) {
            $switches = [...$switches, ...$section->switches()];
            $values = [...$values, ...array_keys($section->secrets()), ...$section->values()];
        }
        $data = [];

        foreach ($switches as $field => $default) {
            $data[$field] = \array_key_exists($field, $row) ? '1' === (string) $row[$field] : $default;
        }

        foreach ($values as $field) {
            $value = (string) ($row[$field] ?? '');
            if ('' !== $value) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    /**
     * Ids of the providers with settings, in checkout order.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return array_keys($this->sections);
    }

    /**
     * Columns of all secrets; they are write-only and stored encrypted.
     *
     * @return list<string>
     */
    public function secretFields(): array
    {
        $fields = [];

        foreach ($this->sections as $section) {
            $fields = [...$fields, ...array_keys($section->secrets())];
        }

        return $fields;
    }

    public function enabled(string $provider): bool
    {
        return $this->enabledIn($this->view(), $provider);
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

    /**
     * A secret in plain text, '' if none is stored: the environment variable of its
     * provider first, then the stored value.
     *
     * @throws \RuntimeException         if it cannot be decrypted
     * @throws \InvalidArgumentException if it does not fit the mode
     */
    public function secret(string $field): string
    {
        $section = $this->owner($field);
        $value = $this->override($section, $field) ?? $this->decrypt($this->stored(), $field);
        $section->checkSecret($field, $value, $this->testMode());

        return $value;
    }

    /**
     * @deprecated use secret('secretKey')
     */
    public function secretKey(): string
    {
        return $this->secret('secretKey');
    }

    /**
     * @deprecated use secret('mollieApiKey')
     */
    public function mollieApiKey(): string
    {
        return $this->secret('mollieApiKey');
    }

    /**
     * @deprecated use secret('webhookSecret')
     */
    public function webhookSecret(): string
    {
        return $this->secret('webhookSecret');
    }

    /**
     * Empty $key, $webhook or $mollieKey keeps the stored secret; a null $mollie
     * keeps the Mollie switch.
     *
     * @deprecated use update()
     */
    public function save(bool $bank, bool $stripe, bool $test, string $key, string $webhook, bool|null $mollie = null, string $mollieKey = ''): void
    {
        $changes = ['bankEnabled' => $bank, 'stripeEnabled' => $stripe, 'testMode' => $test, 'secretKey' => $key, 'webhookSecret' => $webhook, 'mollieApiKey' => $mollieKey];
        if (null !== $mollie) {
            $changes['mollieEnabled'] = $mollie;
        }
        $this->update($changes);
    }

    /**
     * Stores changed columns under the same rules as the backend form. Switches as booleans
     * or '1'/'', secrets in plain text; an empty secret keeps the stored one.
     *
     * @param array<string, mixed> $changes
     *
     * @throws SettingsException
     */
    public function update(array $changes): void
    {
        foreach ($changes as $field => $value) {
            if (\is_bool($value)) {
                $changes[$field] = $value ? '1' : '';
            }
        }
        $this->connection->transactional(
            function () use ($changes): void {
                $this->persist($this->applyChanges($this->lockedRow(), $changes));
            },
        );
    }

    /**
     * Validates submitted changes against the current record and returns the column values
     * to store: secrets encrypted, data of a provider reset as its section decides (e.g.
     * after a change of key or mode). Plaintext secrets in $changes are replaced.
     *
     * @param array<string, mixed> $row     current database record (may be empty)
     * @param array<string, mixed> $changes changed columns; switches as '1'/'', secrets as plaintext
     *
     * @return array<string, mixed>
     */
    public function applyChanges(array $row, array $changes): array
    {
        $current = self::read($this->sections, $row);
        $change = new SettingsChange(
            $current,
            $changes,
            function (string $encrypted): string {
                try {
                    return $this->cipher->decrypt($encrypted);
                } catch (\RuntimeException) {
                    return '';
                }
            },
            $this->assertNothingOpen(...),
        );
        $update = $changes;
        $switches = ['testMode'];
        $updates = [];
        // Columns of the record itself, which the form passes along.
        $known = ['id', 'tstamp', 'testMode'];

        foreach ($this->sections as $section) {
            $known = [...$known, ...array_keys($section->switches()), ...array_keys($section->secrets()), ...$section->values()];
        }
        // Every column belongs to a provider section; anything else could be a secret of
        // a provider whose section is missing and must not be stored as submitted.
        if ([] !== ($unknown = array_diff(array_keys($changes), $known))) {
            throw new \LogicException('Payment settings without provider section: '.implode(', ', $unknown));
        }

        foreach ($this->sections as $section) {
            foreach (array_keys($section->secrets()) as $field) {
                unset($update[$field]);
            }
            $switches = [...$switches, ...array_keys($section->switches())];
            // Every section validates before anything is stored.
            $updates[] = [$section, $section->apply($change)];
        }

        foreach ($switches as $field) {
            if (\array_key_exists($field, $update)) {
                $update[$field] = $change->switch($field) ? '1' : '';
            }
        }

        foreach ($updates as [$section, $values]) {
            foreach ($values as $field => $value) {
                $update[$field] = \array_key_exists($field, $section->secrets()) && '' !== $value ? $this->cipher->encrypt((string) $value) : $value;
            }
        }

        return $update;
    }

    private function assertNothingOpen(string $provider, bool $testMode, string $reason, string $name): void
    {
        $open = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_nw_payment WHERE provider = ? AND test_mode = ? AND status IN ('open', 'pending')", [$provider, $testMode ? 1 : 0]);
        $refunds = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_nw_payment_refund r INNER JOIN tl_nw_payment p ON p.id = r.payment_id WHERE p.provider = ? AND p.test_mode = ? AND r.succeeded = 0 AND r.status IN ('pending', 'requires_action')", [$provider, $testMode ? 1 : 0]);
        if ($open + $refunds > 0) {
            throw new SettingsException($reason, $name.': Es gibt noch offene Zahlungen oder Erstattungen im bisherigen Modus. Zuerst abschließen, dann Modus oder Schlüssel wechseln.');
        }
    }

    public function prepareWebhook(string $url): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Webhook registration requires a committed operation.');
        }
        $this->connection->transactional(
            function () use ($url): void {
                $data = self::read($this->sections, $this->lockedRow());
                $identity = hash('sha256', $url.':'.($this->testMode() ? 'test' : 'live').':'.$this->secret('secretKey'));
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
                $register(self::read($this->sections, $this->lockedRow()));
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
     * What is missing before payments work, from one read of the record.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $view = $this->view();
        $missing = [];
        $standalone = false;

        foreach ($this->sections as $id => $section) {
            $missing = [...$missing, ...$section->missing($view)];
            $standalone = $standalone || ($section->standalone() && $this->enabledIn($view, $id));
        }
        if ([] === $missing && !$standalone) {
            $missing[] = 'Zahlung: mindestens einen Anbieter einrichten';
        }

        return $missing;
    }

    /**
     * Creates the record on first use; its column defaults match self::SWITCHES.
     * tstamp 0 keeps this unsaved state out of the versions.
     */
    public function ensureRecord(): void
    {
        $this->connection->executeStatement('INSERT IGNORE INTO tl_nw_payment_settings (id, tstamp) VALUES (1, 0)');
    }

    private function view(): SettingsView
    {
        $data = $this->stored();
        $testMode = $this->modeOf($data);

        return new SettingsView($data, $testMode, fn (array $data, string $field): string => $this->decryptSecret($data, $field, $testMode));
    }

    private function enabledIn(SettingsView $view, string $provider): bool
    {
        try {
            return isset($this->sections[$provider]) && $this->sections[$provider]->enabled($view);
        } catch (\InvalidArgumentException|\RuntimeException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $data stored() format
     */
    private function decryptSecret(array $data, string $field, bool $testMode): string
    {
        $section = $this->owner($field);
        $value = $this->override($section, $field) ?? $this->decrypt($data, $field);
        $section->checkSecret($field, $value, $testMode);

        return $value;
    }

    private function owner(string $field): ProviderSettingsInterface
    {
        foreach ($this->sections as $section) {
            if (\array_key_exists($field, $section->secrets())) {
                return $section;
            }
        }

        throw new \InvalidArgumentException('Unknown payment secret.');
    }

    /**
     * The value of the secret's environment variable, null without one.
     */
    private function override(ProviderSettingsInterface $section, string $field): string|null
    {
        $environment = $section->secrets()[$field];
        $override = '' === $environment ? false : getenv($environment);

        return false !== $override && '' !== $override ? $override : null;
    }

    /**
     * @param array<string, mixed> $data stored() format
     */
    private function decrypt(array $data, string $field): string
    {
        $encrypted = $data[$field] ?? '';

        return \is_string($encrypted) && '' !== $encrypted ? $this->cipher->decrypt($encrypted) : '';
    }

    /**
     * @return list<ProviderSettingsInterface>
     */
    private static function builtIn(): array
    {
        return [new BankTransferSettings(), new StripeSettings(), new MollieSettings(), new OnSiteSettings()];
    }

    /**
     * @param iterable<ProviderSettingsInterface> $sections
     *
     * @return array<string, ProviderSettingsInterface> provider id => section, in checkout order
     */
    private static function index(iterable $sections): array
    {
        $list = [...$sections];
        usort($list, static fn (ProviderSettingsInterface $a, ProviderSettingsInterface $b): int => $b::priority() <=> $a::priority());
        $indexed = [];

        foreach ($list as $section) {
            if (isset($indexed[$section::provider()])) {
                throw new \LogicException('Two payment settings sections for provider "'.$section::provider().'".');
            }
            $indexed[$section::provider()] = $section;
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $data stored() format
     */
    private function modeOf(array $data): bool
    {
        $override = getenv('NW_PAYMENT_STRIPE_TEST_MODE');

        return false !== $override && '' !== $override ? '1' === $override : (bool) $data['testMode'];
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
