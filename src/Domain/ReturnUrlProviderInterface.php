<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

/**
 * Implemented next to PayableResolverInterface by consumers that have a page of
 * their own for the payable (order confirmation, booking). The return page sends
 * the customer there once the payment is decided or cancelled, and links to it
 * while the payment is checked.
 */
interface ReturnUrlProviderInterface
{
    /**
     * Absolute URL of the consumer's page for this payment, null if there is none.
     */
    public function returnUrl(Payment $payment): string|null;
}
