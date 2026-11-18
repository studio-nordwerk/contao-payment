<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Provider;

use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MollieClient implements MollieClientInterface
{
    private const BASE = 'https://api.mollie.com';

    public function __construct(
        private PaymentSettings $settings,
        private HttpClientInterface $http,
    ) {
    }

    public function request(string $method, string $path, array $parameters = [], string|null $idempotencyKey = null): array
    {
        if (!\in_array($method, ['get', 'post'], true) || !str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Invalid Mollie request.');
        }
        $key = $this->settings->mollieApiKey();
        if ('' === $key) {
            throw new \RuntimeException('Mollie API key is missing.');
        }
        $base = getenv('NW_PAYMENT_MOLLIE_API_BASE');
        if (false !== $base && '' !== $base) {
            if (!$this->settings->testMode()) {
                throw new \RuntimeException('A custom Mollie endpoint requires test mode.');
            }
        } else {
            $base = self::BASE;
        }
        $headers = ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'];
        $options = ['headers' => $headers, 'timeout' => 15, 'max_redirects' => 0];
        if (null !== $idempotencyKey) {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }
        if ('get' === $method) {
            $options['query'] = $parameters;
        } else {
            $options['json'] = $parameters;
        }

        try {
            $response = $this->http->request(strtoupper($method), rtrim((string) $base, '/').'/v2'.$path, $options);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            // The message may contain the request URL; never the key.
            throw new \RuntimeException('Mollie request failed: '.$exception::class, 0, $exception);
        }
        if ($status >= 200 && $status < 300) {
            return $data;
        }
        // A request Mollie rejects (unknown id, invalid data) is the caller's problem
        // and no reason to retry.
        if ($status >= 400 && $status < 500 && 429 !== $status && 401 !== $status && 403 !== $status) {
            throw new \InvalidArgumentException('Mollie rejected the request ('.$status.').');
        }

        throw new \RuntimeException('Mollie request failed ('.$status.').');
    }
}
