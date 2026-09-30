<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

final readonly class CheckoutResult
{
    public function __construct(
        public string $reference,
        public string|null $redirectUrl = null,
    ) {
    }
}
