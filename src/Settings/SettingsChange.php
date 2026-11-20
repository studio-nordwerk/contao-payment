<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

/**
 * A submitted change of the settings record, as seen by the provider sections.
 */
final readonly class SettingsChange
{
    /**
     * @param array<string, mixed>                         $current           stored() format of the current record
     * @param array<string, mixed>                         $changes           changed columns; switches as '1'/'', secrets in plain text
     * @param \Closure(string): string                     $decrypt           decrypts a stored secret, '' if it cannot
     * @param \Closure(string, bool, string, string): void $assertNothingOpen
     */
    public function __construct(
        public array $current,
        private array $changes,
        private \Closure $decrypt,
        private \Closure $assertNothingOpen,
    ) {
    }

    /**
     * The test mode after the change; it applies to all online providers.
     */
    public function testMode(): bool
    {
        return $this->switch('testMode');
    }

    public function modeChanged(): bool
    {
        return (bool) $this->current['testMode'] !== $this->testMode();
    }

    public function switch(string $field): bool
    {
        return \array_key_exists($field, $this->changes) ? '1' === (string) $this->changes[$field] : (bool) ($this->current[$field] ?? false);
    }

    /**
     * Submitted value, trimmed; '' if the field was not submitted or left empty.
     */
    public function submitted(string $field): string
    {
        return trim((string) ($this->changes[$field] ?? ''));
    }

    /**
     * Whether the change contains this field at all (also when left empty).
     */
    public function has(string $field): bool
    {
        return \array_key_exists($field, $this->changes);
    }

    public function stored(string $field): bool
    {
        return '' !== (string) ($this->current[$field] ?? '');
    }

    /**
     * The stored secret in plain text, '' if there is none or it cannot be decrypted.
     */
    public function storedSecret(string $field): string
    {
        return $this->stored($field) ? ($this->decrypt)((string) $this->current[$field]) : '';
    }

    /**
     * Throws if payments or refunds of this provider in the current mode are still
     * open: they depend on the key that a change drops.
     *
     * @throws SettingsException
     */
    public function assertNothingOpen(string $provider, string $reason, string $name): void
    {
        ($this->assertNothingOpen)($provider, (bool) $this->current['testMode'], $reason, $name);
    }
}
