<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Provider;

/**
 * Mollie answered with a clear client error (for example 404 or 422). Not thrown
 * for 401, 403, 408, 409 (idempotent request still processing) and 429, which are
 * no final answer.
 */
final class MollieRejectedException extends \InvalidArgumentException
{
}
