<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

/**
 * The provider definitively refused to create a refund (not an outage, timeout or
 * "still processing"). Only this releases the reservation of the refund operation.
 */
final class RefundRejectedException extends \InvalidArgumentException
{
}
