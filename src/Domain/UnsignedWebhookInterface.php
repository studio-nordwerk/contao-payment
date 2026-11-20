<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

use Symfony\Component\HttpFoundation\Request;

/**
 * Providers whose webhook only names a payment, without a signature. The webhook
 * route first checks that the payment is ours, so strangers cannot make us call
 * the provider's API.
 */
interface UnsignedWebhookInterface
{
    /**
     * The provider reference the webhook names, or null if it names none in a valid
     * format (parseWebhook() then rejects it).
     */
    public function webhookReference(Request $request): string|null;
}
