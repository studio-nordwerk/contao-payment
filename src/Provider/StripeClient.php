<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Provider;

use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Stripe\StripeClient as SdkClient;

final readonly class StripeClient implements StripeClientInterface
{
    public function __construct(private PaymentSettings $settings)
    {
    }

    public function request(string $method, string $path, array $parameters = [], string|null $idempotencyKey = null): array
    {
        if (!\in_array($method, ['get', 'post', 'delete'], true)) {
            throw new \InvalidArgumentException('Invalid Stripe method.');
        }
        $options = ['api_key' => $this->settings->secretKey()];
        $base = getenv('NW_PAYMENT_STRIPE_API_BASE');
        if (false !== $base && '' !== $base) {
            if (!$this->settings->testMode()) {
                throw new \RuntimeException('A custom Stripe endpoint requires test mode.');
            }
            $options['api_base'] = $base;
        }
        $client = new SdkClient($options);
        $requestOptions = null === $idempotencyKey ? [] : ['idempotency_key' => $idempotencyKey];

        return $client->request($method, $path, $parameters, $requestOptions)->toArray();
    }
}
