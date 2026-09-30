<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\BankTransfer;

final class Iban
{
    public static function normalize(string $iban): string
    {
        return strtoupper(str_replace(' ', '', $iban));
    }

    public static function valid(string $iban): bool
    {
        $iban = self::normalize($iban);
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $iban)) {
            return false;
        }
        $remainder = 0;

        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $character) {
            $digits = ctype_digit($character) ? $character : (string) (\ord($character) - 55);

            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return 1 === $remainder;
    }
}
