<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

enum PaymentStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function allows(self $next): bool
    {
        return \in_array($next, match ($this) {
            self::Open => [self::Pending, self::Paid, self::Failed, self::Expired],
            self::Pending => [self::Paid, self::Failed, self::Expired],
            self::Paid => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::Refunded],
            default => [],
        }, true);
    }
}
