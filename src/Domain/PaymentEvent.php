<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

final readonly class PaymentEvent
{
    public function __construct(
        public string $eventId,
        public int $paymentId,
        public string $reference,
        public PaymentStatus $status,
        public Money $money,
        public int $refundedCents = 0,
        public bool $testMode = true,
    ) {
        if ('' === $eventId || $paymentId < 1) {
            throw new \InvalidArgumentException('Invalid payment event.');
        }
    }
}
