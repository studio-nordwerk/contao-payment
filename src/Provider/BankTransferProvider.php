<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Provider;

use Nordwerk\PaymentBundle\Domain\CheckoutResult;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\Payment;
use Nordwerk\PaymentBundle\Domain\PaymentEvent;
use Nordwerk\PaymentBundle\Domain\PaymentProviderInterface;
use Nordwerk\PaymentBundle\Domain\PaymentRequest;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;
use Nordwerk\PaymentBundle\Domain\RefundResult;
use Symfony\Component\HttpFoundation\Request;

final class BankTransferProvider implements PaymentProviderInterface
{
    public function createCheckout(PaymentRequest $request): CheckoutResult
    {
        return new CheckoutResult('manual:'.$request->payment->id);
    }

    public function parseWebhook(Request $request): PaymentEvent
    {
        throw new \InvalidArgumentException('Bank transfers have no webhook.');
    }

    public function refund(Payment $payment, Money $money): RefundResult
    {
        return new RefundResult('manual:'.$payment->id.':'.($payment->refundedCents + $money->cents), true);
    }

    public function fetchStatus(Payment $payment): PaymentStatus
    {
        return $payment->status;
    }
}
