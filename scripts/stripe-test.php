<?php

declare(strict_types=1);

require __DIR__.'/../app/vendor/autoload.php';

use Stripe\StripeClient;

$key = getenv('STRIPE_TEST_SECRET_KEY');
if (!is_string($key) || !preg_match('/^(sk|rk)_test_/', $key)) {
    throw new RuntimeException('STRIPE_TEST_SECRET_KEY with test-mode credentials is required.');
}

try {
    $session = (new StripeClient($key))->checkout->sessions->create(
        [
            'mode' => 'payment',
            'success_url' => 'https://example.com/payment-test-return',
            'cancel_url' => 'https://example.com/payment-test-return',
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => 100, 'product_data' => ['name' => 'Manueller Payment-Smoke-Test']]]],
        ],
        ['idempotency_key' => 'manual-smoke:'.bin2hex(random_bytes(16))],
    );
    if ($session->livemode) {
        throw new RuntimeException('Unexpected live-mode response.');
    }
    echo $session->url."\n";
} catch (Throwable) {
    fwrite(STDERR, "Stripe test request failed. Check credentials and connection.\n");
    exit(1);
}
