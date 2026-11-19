<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Provider;

use Nordwerk\PaymentBundle\Domain\Money;

/**
 * Mollie amounts are strings with the decimals of the currency ("12.34").
 * Conversion uses integers and strings only, never floats.
 */
final class MollieAmount
{
    /**
     * Mollie uses no decimals for these; Money counts hundredths, so they are
     * not supported.
     */
    private const WITHOUT_DECIMALS = ['ISK', 'JPY'];

    /**
     * @return array{currency: string, value: string}
     */
    public static function format(Money $money): array
    {
        if (\in_array($money->currency, self::WITHOUT_DECIMALS, true)) {
            throw new \InvalidArgumentException('Währung wird von Mollie ohne Nachkommastellen geführt und hier nicht unterstützt.');
        }

        return ['currency' => $money->currency, 'value' => \sprintf('%d.%02d', intdiv($money->cents, 100), $money->cents % 100)];
    }

    public static function parse(mixed $amount): Money
    {
        if (!\is_array($amount) || !\is_string($amount['currency'] ?? null) || !\is_string($amount['value'] ?? null)) {
            throw new \InvalidArgumentException('Invalid Mollie amount.');
        }
        if (!preg_match('/^(0|[1-9][0-9]{0,11})\.([0-9]{2})$/D', $amount['value'], $match) || \in_array($amount['currency'], self::WITHOUT_DECIMALS, true)) {
            throw new \InvalidArgumentException('Invalid Mollie amount.');
        }

        return new Money((int) $match[1] * 100 + (int) $match[2], $amount['currency']);
    }
}
