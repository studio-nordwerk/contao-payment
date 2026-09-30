<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

final readonly class PaymentRequest
{
    public function __construct(
        public Payment $payment,
        public string $returnUrl,
        public string $description,
    ) {
    }
}
