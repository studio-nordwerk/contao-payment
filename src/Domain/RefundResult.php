<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

final readonly class RefundResult
{
    public RefundStatus $status;

    public function __construct(
        public string $reference,
        public bool $succeeded,
        RefundStatus|null $status = null,
    ) {
        $this->status = $status ?? ($succeeded ? RefundStatus::Succeeded : RefundStatus::Pending);
        if ($succeeded !== (RefundStatus::Succeeded === $this->status)) {
            throw new \InvalidArgumentException('Inconsistent refund status.');
        }
    }
}
