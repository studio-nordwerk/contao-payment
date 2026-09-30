<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

final readonly class Payment
{
    public function __construct(
        public int $id,
        public string $payableType,
        public string $payableId,
        public Money $money,
        public string $provider,
        public string $reference,
        public PaymentStatus $status,
        public int $refundedCents = 0,
        public string $token = '',
        public bool $testMode = true,
    ) {
    }
}
