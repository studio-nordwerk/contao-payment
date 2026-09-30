<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

/** Optional richer reconciliation for cumulative refunds. */
interface PaymentSnapshotProviderInterface
{
    public function fetchSnapshot(Payment $payment): PaymentEvent;
}
