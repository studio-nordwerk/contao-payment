<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

final readonly class RefundResult
{
    public function __construct(
        public string $reference,
        public bool $succeeded,
    ) {
    }
}
