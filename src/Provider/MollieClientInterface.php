<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Provider;

interface MollieClientInterface
{
    /**
     * Calls the Mollie API v2. $path starts with "/", for example "/payments/tr_…".
     * GET sends $parameters as query, POST as JSON body.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $parameters = [], string|null $idempotencyKey = null): array;
}
