<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\BankTransfer;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class GiroCode
{
    public static function payload(string $name, string $iban, int $cents, string $reference, string $bic = ''): string
    {
        foreach ([[$name, 70], [$reference, 140]] as [$value, $limit]) {
            if ('' === trim((string) $value) || !mb_check_encoding((string) $value, 'UTF-8') || mb_strlen((string) $value, 'UTF-8') > $limit || preg_match('/[\x00-\x1f\x7f]/u', (string) $value)) {
                throw new \InvalidArgumentException('Invalid EPC text.');
            }
        }
        $bic = strtoupper(trim($bic));
        if (!Iban::valid($iban) || ('' !== $bic && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/D', $bic)) || $cents < 1 || $cents > 99999999999) {
            throw new \InvalidArgumentException('Invalid EPC bank details or amount.');
        }
        $payload = implode("\n", ['BCD', '002', '1', 'SCT', $bic, $name, Iban::normalize($iban), 'EUR'.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT), '', '', $reference]);
        if (\strlen($payload) > 331) {
            throw new \InvalidArgumentException('EPC payload exceeds 331 bytes.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $bank
     */
    public static function image(array $bank, int $cents, string $reference, string $currency = 'EUR', string $method = 'bank_transfer'): string|null
    {
        if ('EUR' !== $currency || 'bank_transfer' !== $method) {
            return null;
        }

        try {
            $payload = self::payload((string) ($bank['accountHolder'] ?? ''), (string) ($bank['iban'] ?? ''), $cents, $reference, (string) ($bank['bic'] ?? ''));
        } catch (\InvalidArgumentException) {
            return null;
        }

        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'versionMax' => 13,
            'scale' => 5,
            'drawLightModules' => true,
            'imageTransparent' => false,
        ])))->render($payload);
    }

    public static function dataUri(string $png): string
    {
        return 'data:image/png;base64,'.base64_encode($png);
    }
}
