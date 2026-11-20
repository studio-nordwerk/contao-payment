<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

/**
 * Providers whose webhooks may not reach the shop (local installations,
 * firewalls) or carry no proof of their content. Their state is therefore read
 * from the provider: when the customer returns, to confirm the refunded total
 * after a refund, and from time to time for paid payments, so refunds made in the
 * provider's dashboard arrive without a webhook.
 */
interface PolledProviderInterface extends PaymentSnapshotProviderInterface
{
}
