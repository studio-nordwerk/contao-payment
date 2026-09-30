<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('nordwerk.payment.payable_resolver')]
interface PayableResolverInterface
{
    public function supports(string $payableType): bool;

    public function paid(Payment $payment): void;

    public function refunded(Payment $payment, int $previousRefundedCents): void;
}
