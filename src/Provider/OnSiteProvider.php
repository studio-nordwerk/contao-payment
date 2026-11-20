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

/**
 * Paid on site, in cash or with PayPal: the seller confirms the payment in the backend.
 */
final class OnSiteProvider implements PaymentProviderInterface
{
    public static function id(): string
    {
        return 'on_site';
    }

    public static function priority(): int
    {
        return 0;
    }

    public function name(): string
    {
        return 'Vor Ort';
    }

    public function label(): string
    {
        return 'Bezahlung vor Ort';
    }

    public function online(): bool
    {
        return false;
    }

    public function createCheckout(PaymentRequest $request): CheckoutResult
    {
        return new CheckoutResult('on_site:'.$request->payment->id);
    }

    public function parseWebhook(Request $request): PaymentEvent
    {
        throw new \InvalidArgumentException('On-site payments have no webhook.');
    }

    public function refund(Payment $payment, Money $money, string $operation): RefundResult
    {
        return new RefundResult('on_site:'.$payment->id.':'.($payment->refundedCents + $money->cents), true);
    }

    public function fetchStatus(Payment $payment): PaymentStatus
    {
        return $payment->status;
    }
}
