<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Settings;

/**
 * One read of the settings record, so a check across all providers reads the
 * database once.
 */
final readonly class SettingsView
{
    /**
     * @param array<string, mixed>                           $data   stored() format
     * @param \Closure(array<string, mixed>, string): string $secret decrypts a secret of $data (environment first)
     */
    public function __construct(
        private array $data,
        private bool $testMode,
        private \Closure $secret,
    ) {
    }

    public function switch(string $field): bool
    {
        return (bool) ($this->data[$field] ?? false);
    }

    public function value(string $field): string
    {
        return (string) ($this->data[$field] ?? '');
    }

    /**
     * Decrypted secret, '' if none is stored.
     *
     * @throws \RuntimeException         if it cannot be decrypted
     * @throws \InvalidArgumentException if it does not fit the mode
     */
    public function secret(string $field): string
    {
        return ($this->secret)($this->data, $field);
    }

    public function testMode(): bool
    {
        return $this->testMode;
    }
}
