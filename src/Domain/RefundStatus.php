<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

enum RefundStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
