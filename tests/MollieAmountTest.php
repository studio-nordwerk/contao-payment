<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Provider\MollieAmount;
use Nordwerk\PaymentBundle\Provider\MollieProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MollieAmountTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function amounts(): iterable
    {
        yield 'zero' => [0, '0.00'];
        yield 'cents only' => [5, '0.05'];
        yield 'tens of cents' => [70, '0.70'];
        yield 'whole euros' => [1000, '10.00'];
        yield 'typical' => [1234, '12.34'];
        // 19.99 as a float times 100 would be 1998.9999999999998.
        yield 'float trap' => [1999, '19.99'];
        yield 'large' => [99999999999, '999999999.99'];
    }

    #[DataProvider('amounts')]
    public function testCentsAndStringsConvertBothWays(int $cents, string $value): void
    {
        $this->assertSame(['currency' => 'EUR', 'value' => $value], MollieAmount::format(new Money($cents)));
        $this->assertTrue(MollieAmount::parse(['currency' => 'EUR', 'value' => $value])->equals(new Money($cents)));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        foreach (['12.3', '12', '12,34', '12.345', '-1.00', '+1.00', '01.00', '1e3', ' 1.00', '1.00 ', '.50', '', 'abc', '1 000.00', '1000000000000.00'] as $value) {
            yield 'value "'.$value.'"' => [['currency' => 'EUR', 'value' => $value]];
        }
        yield 'float value' => [['currency' => 'EUR', 'value' => 12.34]];
        yield 'int value' => [['currency' => 'EUR', 'value' => 12]];
        yield 'no currency' => [['value' => '12.34']];
        yield 'lowercase currency' => [['currency' => 'eur', 'value' => '12.34']];
        yield 'no array' => ['12.34'];
        yield 'null' => [null];
        yield 'currency without decimals' => [['currency' => 'JPY', 'value' => '100.00']];
    }

    #[DataProvider('invalid')]
    public function testInvalidFormatsAreRejected(mixed $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MollieAmount::parse($amount);
    }

    public function testCurrenciesWithoutDecimalsAreNotSent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MollieAmount::format(new Money(100, 'JPY'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'public https' => ['https://shop.example/_nw/payment/webhook/mollie', true];
        yield 'public with port' => ['https://shop.example:8443/x', true];
        yield 'public ip' => ['https://8.8.8.8/x', true];
        yield 'localhost' => ['http://localhost:8084/x', false];
        yield 'subdomain of localhost' => ['https://shop.localhost/x', false];
        yield '.test domain' => ['https://shop.test/x', false];
        yield 'loopback' => ['http://127.0.0.1:8084/x', false];
        yield 'ipv6 loopback' => ['http://[::1]:8084/x', false];
        yield 'private 192.168' => ['http://192.168.1.20/x', false];
        yield 'private 10' => ['http://10.0.0.5/x', false];
        yield 'private 172.16' => ['http://172.16.0.5/x', false];
        yield 'docker service name' => ['http://caddy/x', false];
        yield 'no host' => ['/relative', false];
    }

    #[DataProvider('urls')]
    public function testWebhookUrlOnlyForPublicHosts(string $url, bool $public): void
    {
        $this->assertSame($public, MollieProvider::isPublicUrl($url));
    }
}
