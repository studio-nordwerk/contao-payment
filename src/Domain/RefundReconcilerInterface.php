<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

/**
 * Online providers whose refunds can stay pending: the cron reads their state again.
 */
interface RefundReconcilerInterface
{
    /**
     * Current state of one refund together with its payment.
     *
     * @param int    $amount    reserved amount of the refund operation in cents
     * @param string $operation operation token of the reservation
     */
    public function refundEvent(string $eventId, Payment $payment, string $refundReference, int $amount, string $operation): PaymentEvent;
}
