<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

interface PaymentSnapshotProviderInterface
{
    public function fetchSnapshot(Payment $payment): PaymentEvent;
}
