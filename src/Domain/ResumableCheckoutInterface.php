<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

/**
 * Providers whose checkout was already created at the provider and can be
 * continued, e.g. when the customer returns to the shop and starts the same
 * payment again.
 */
interface ResumableCheckoutInterface
{
    /**
     * @throws \InvalidArgumentException if the provider's checkout does not match the stored payment
     */
    public function resumeCheckout(PaymentRequest $request): CheckoutResult;
}
