<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

use Symfony\Component\HttpFoundation\Request;

interface PaymentProviderInterface
{
    public function createCheckout(PaymentRequest $request): CheckoutResult;

    public function parseWebhook(Request $request): PaymentEvent;

    public function refund(Payment $payment, Money $money): RefundResult;

    public function fetchStatus(Payment $payment): PaymentStatus;
}
