<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use chillerlan\QRCode\QRCode;
use Nordwerk\PaymentBundle\OnSite\PayPalMe;
use PHPUnit\Framework\TestCase;

final class OnSiteTest extends TestCase
{
    public function testPayPalMeAcceptsTheUsualSpellings(): void
    {
        foreach (['hofladen', 'paypal.me/hofladen', 'https://paypal.me/hofladen/', 'https://www.paypal.com/paypalme/hofladen', 'PayPal.Me/hofladen'] as $input) {
            $this->assertSame('hofladen', PayPalMe::normalize($input));
        }
        $this->assertSame('', PayPalMe::normalize('  '));

        foreach (['paypal.me/hof-laden', 'https://evil.example/hofladen', 'paypal.me/'.str_repeat('a', 21), 'javascript:alert(1)', 'paypal.me/hofladen/12EUR'] as $invalid) {
            try {
                PayPalMe::normalize($invalid);
                $this->fail('Invalid PayPal.me link was accepted: '.$invalid);
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('paypal.me', $exception->getMessage());
            }
        }
    }

    public function testLinkAndQrCarryTheAmount(): void
    {
        $this->assertSame('https://paypal.me/hofladen/12.50EUR', PayPalMe::url('hofladen', 1250));
        $this->assertSame('https://paypal.me/hofladen/0.05EUR', PayPalMe::url('paypal.me/hofladen', 5));
        $png = PayPalMe::image('hofladen', 1250);
        $this->assertNotNull($png);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $this->assertSame('https://paypal.me/hofladen/12.50EUR', (new QRCode())->readFromBlob($png)->data);
        $this->assertNull(PayPalMe::image('', 1250));
        $this->expectException(\InvalidArgumentException::class);
        PayPalMe::url('hofladen', 0);
    }
}
