<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

final readonly class Money
{
    public function __construct(
        public int $cents,
        public string $currency = 'EUR',
    ) {
        if ($cents < 0 || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException('Money requires nonnegative cents and an ISO currency.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }
}
