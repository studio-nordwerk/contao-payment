<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Provider;

interface StripeClientInterface
{
    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $parameters = [], string|null $idempotencyKey = null): array;
}
