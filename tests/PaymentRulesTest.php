<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;
use Nordwerk\PaymentBundle\Settings\SecretCipher;
use PHPUnit\Framework\TestCase;

final class PaymentRulesTest extends TestCase
{
    public function testTransitionsCannotRegress(): void
    {
        $this->assertTrue(PaymentStatus::Open->allows(PaymentStatus::Paid));
        $this->assertTrue(PaymentStatus::Pending->allows(PaymentStatus::Paid));
        $this->assertTrue(PaymentStatus::Paid->allows(PaymentStatus::PartiallyRefunded));
        $this->assertTrue(PaymentStatus::PartiallyRefunded->allows(PaymentStatus::Refunded));
        $this->assertFalse(PaymentStatus::Paid->allows(PaymentStatus::Expired));
        $this->assertFalse(PaymentStatus::Refunded->allows(PaymentStatus::Paid));
        $this->assertFalse(PaymentStatus::Expired->allows(PaymentStatus::Open));
    }

    public function testMoneyRejectsNegativeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(-1, 'EUR');
    }

    public function testCipherIsRandomizedAuthenticatedAndBoundToAppSecret(): void
    {
        $cipher = new SecretCipher('test-application-secret');
        $a = $cipher->encrypt('fixture-value');
        $this->assertNotSame($a, $cipher->encrypt('fixture-value'));
        $this->assertSame('fixture-value', $cipher->decrypt($a));
        $this->expectException(\RuntimeException::class);
        (new SecretCipher('other-application-secret'))->decrypt($a);
    }
}
