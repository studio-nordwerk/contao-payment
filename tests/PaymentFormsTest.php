<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Tests;

use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\Payment;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentFormsTest extends TestCase
{
    public function testEveryPaymentFormHasItsOwnOperationToken(): void
    {
        $view = new class() {
            public string $error = '';
            public string $notice = '';
            public string $requestToken = 'csrf-fixture';
            public string $operation = 'old-shared-token';
            /**
             * @var list<array<string, mixed>>
             */
            public array $events = [];
            /**
             * @var list<Payment>
             */
            public array $payments = [];
        };
        $view->payments = [
            new Payment(1, 'fixture', 'one', new Money(1000), 'stripe', 'cs_one', PaymentStatus::Paid),
            new Payment(2, 'fixture', 'two', new Money(1000), 'stripe', 'cs_two', PaymentStatus::Paid),
        ];
        $render = function (): string {
            ob_start();
            include __DIR__.'/../Resources/contao/templates/backend/be_nw_payments.html5';

            return (string) ob_get_clean();
        };
        $html = $render->call($view);
        preg_match_all('/name="operation" value="([^"]+)"/', $html, $matches);
        $this->assertCount(2, $matches[1]);
        $this->assertCount(2, array_unique($matches[1]));

        foreach ($matches[1] as $operation) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $operation);
        }
    }
}
