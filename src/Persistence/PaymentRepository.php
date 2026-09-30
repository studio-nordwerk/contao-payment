<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Persistence;

use Doctrine\DBAL\Connection;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\Payment;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;

final readonly class PaymentRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id, bool $lock = false): Payment
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_nw_payment WHERE id = ?'.($lock ? ' FOR UPDATE' : ''), [$id]);
        if (false === $row) {
            throw new \InvalidArgumentException('Zahlung nicht gefunden.');
        }

        return $this->hydrate($row);
    }

    public function forPayable(string $type, string $id): Payment|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_nw_payment WHERE payable_type = ? AND payable_id = ?', [$type, $id]);

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * @return list<Payment>
     */
    public function recent(): array
    {
        return array_map($this->hydrate(...), $this->connection->fetchAllAssociative('SELECT * FROM tl_nw_payment ORDER BY id DESC LIMIT 100'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Payment
    {
        return new Payment((int) $row['id'], (string) $row['payable_type'], (string) $row['payable_id'], new Money((int) $row['amount'], (string) $row['currency']), (string) $row['provider'], (string) $row['provider_reference'], PaymentStatus::from((string) $row['status']), (int) $row['refunded_amount'], (string) $row['return_token'], (bool) $row['test_mode']);
    }
}
