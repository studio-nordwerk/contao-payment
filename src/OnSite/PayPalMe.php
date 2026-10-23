<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\OnSite;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * PayPal.me links for paying on site, e.g. with a QR code at the farm shop shelf or in
 * the order mail. The link carries the amount, so the customer only confirms.
 */
final class PayPalMe
{
    /**
     * Accepts "name", "paypal.me/name" or "https://www.paypal.com/paypalme/name".
     * Returns the name, or '' for empty input.
     */
    public static function normalize(string $input): string
    {
        $input = trim($input);
        if ('' === $input) {
            return '';
        }
        if (!preg_match('#^(?:(?:https?://)?(?:www\.)?(?:paypal\.me|paypal\.com/paypalme)/)?([A-Za-z0-9]{1,20})/?$#Di', $input, $match)) {
            throw new \InvalidArgumentException('Bitte einen PayPal.me-Link wie paypal.me/IhrName eingeben.');
        }

        return $match[1];
    }

    public static function url(string $name, int $cents): string
    {
        if ($cents < 1) {
            throw new \InvalidArgumentException('Invalid PayPal.me amount.');
        }

        return 'https://paypal.me/'.self::normalize($name).'/'.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).'EUR';
    }

    /**
     * PNG with the link for the given amount, or null without a PayPal.me name.
     */
    public static function image(string $name, int $cents): string|null
    {
        if ('' === $name) {
            return null;
        }

        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => 5,
            'drawLightModules' => true,
            'imageTransparent' => false,
        ])))->render(self::url($name, $cents));
    }
}
