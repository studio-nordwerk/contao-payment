<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Persistence;

use Doctrine\DBAL\Connection;
use Nordwerk\PaymentBundle\Domain\CheckoutResult;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\PayableResolverInterface;
use Nordwerk\PaymentBundle\Domain\PaymentEvent;
use Nordwerk\PaymentBundle\Domain\PaymentProviderInterface;
use Nordwerk\PaymentBundle\Domain\PaymentRequest;
use Nordwerk\PaymentBundle\Domain\PaymentSnapshotProviderInterface;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;
use Nordwerk\PaymentBundle\Domain\RefundResult;
use Nordwerk\PaymentBundle\Provider\BankTransferProvider;
use Nordwerk\PaymentBundle\Provider\StripeProvider;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Psr\Log\LoggerInterface;

final readonly class PaymentService
{
    /**
     * @param iterable<PayableResolverInterface> $resolvers
     */
    public function __construct(
        private Connection $connection,
        private PaymentRepository $repository,
        private BankTransferProvider $bank,
        private StripeProvider $stripe,
        private PaymentSettings $settings,
        private iterable $resolvers,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function methods(): array
    {
        $methods = [];

        foreach (['bank_transfer' => 'Vorkasse per Überweisung', 'stripe' => 'Online bezahlen (Stripe)'] as $id => $label) {
            if ($this->settings->enabled($id)) {
                $methods[$id] = $label;
            }
        }

        return $methods;
    }

    public function provider(string $provider): PaymentProviderInterface
    {
        return match ($provider) {
            'bank_transfer' => $this->bank,
            'stripe' => $this->stripe,
            default => throw new \InvalidArgumentException('Unknown payment provider.'),
        };
    }

    public function start(string $type, string $id, Money $money, string $provider, string $origin, string $description): CheckoutResult
    {
        if (!$this->settings->enabled($provider) || $money->cents < 1) {
            throw new \InvalidArgumentException('Zahlart nicht verfügbar.');
        }

        // The payable uniqueness and row lock also serialize simultaneous checkout retries.
        return $this->connection->transactional(
            function () use ($type, $id, $money, $provider, $origin, $description): CheckoutResult {
                $this->connection->executeStatement('INSERT INTO tl_nw_payment (tstamp, payable_type, payable_id, amount, currency, provider, status, created_at, updated_at, return_token, test_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', [time(), $type, $id, $money->cents, $money->currency, $provider, 'open', time(), time(), bin2hex(random_bytes(32)), $this->settings->testMode() ? 1 : 0]);
                $payment = $this->repository->find((int) $this->connection->lastInsertId(), true);
                if ($payment->provider !== $provider || !$payment->money->equals($money)) {
                    throw new \InvalidArgumentException('Payment retry does not match original checkout.');
                }
                if (PaymentStatus::Open !== $payment->status && PaymentStatus::Pending !== $payment->status) {
                    return new CheckoutResult($payment->reference, $origin.'/_nw/payment/return/'.$payment->token);
                }
                $result = $this->provider($provider)->createCheckout(new PaymentRequest($payment, $origin.'/_nw/payment/return/'.$payment->token, $description));
                $this->connection->update('tl_nw_payment', ['provider_reference' => $result->reference, 'updated_at' => time()], ['id' => $payment->id]);

                return $result;
            },
        );
    }

    public function apply(string $provider, PaymentEvent $event): bool
    {
        return $this->connection->transactional(
            function () use ($provider, $event): bool {
                $payment = $this->repository->find($event->paymentId, true);
                if ($payment->provider !== $provider || $payment->reference !== $event->reference || !$payment->money->equals($event->money) || $payment->testMode !== $event->testMode || $event->refundedCents > $payment->money->cents || $event->refundedCents < 0) {
                    throw new \InvalidArgumentException('Payment event does not match payment.');
                }
                if (\in_array($event->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true) && ($event->refundedCents < 1 || (PaymentStatus::Refunded === $event->status) !== ($event->refundedCents === $payment->money->cents))) {
                    throw new \InvalidArgumentException('Refund event has inconsistent cumulative amount.');
                }
                if ($this->connection->fetchOne('SELECT id FROM tl_nw_payment_event WHERE provider = ? AND provider_event_id = ?', [$provider, $event->eventId])) {
                    return false;
                }
                if (\in_array($payment->status, [PaymentStatus::Open, PaymentStatus::Pending], true) && \in_array($event->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
                    // A refund proves prior payment even when webhooks arrive out of order.
                    $this->apply($provider, new PaymentEvent('refund-paid:'.$event->eventId, $payment->id, $payment->reference, PaymentStatus::Paid, $payment->money, testMode: $payment->testMode));
                    $payment = $this->repository->find($payment->id, true);
                }
                $previous = $payment->refundedCents;
                $refundChanged = $event->refundedCents > $previous && \in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true) && \in_array($event->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true);
                $changed = $payment->status->allows($event->status) || $refundChanged;
                if (\in_array($event->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true) && (!$refundChanged || (PaymentStatus::Refunded === $event->status) !== ($event->refundedCents === $payment->money->cents))) {
                    $changed = false;
                }
                $this->connection->insert('tl_nw_payment_event', ['tstamp' => time(), 'payment_id' => $payment->id, 'provider' => $provider, 'provider_event_id' => $event->eventId, 'status' => $event->status->value, 'refunded_amount' => $event->refundedCents, 'received_at' => time(), 'applied' => $changed ? 1 : 0]);
                if (!$changed) {
                    return false;
                }
                $this->connection->update('tl_nw_payment', ['status' => $event->status->value, 'refunded_amount' => max($previous, $event->refundedCents), 'updated_at' => time(), 'tstamp' => time()], ['id' => $payment->id]);
                if ($refundChanged) {
                    $this->connection->executeStatement('UPDATE tl_nw_payment_refund SET succeeded = 1 WHERE provider_reference <> \'\' AND payment_id = ? AND succeeded = 0 AND amount <= ?', [$payment->id, $event->refundedCents - $previous]);
                }
                $updated = $this->repository->find($payment->id);

                foreach ($this->resolvers as $resolver) {
                    if ($resolver->supports($payment->payableType)) {
                        if (PaymentStatus::Paid === $updated->status) {
                            $resolver->paid($updated);
                        } elseif ($refundChanged) {
                            $resolver->refunded($updated, $previous);
                        }
                    }
                }

                return true;
            },
        );
    }

    public function confirmManual(int $id): void
    {
        $payment = $this->repository->find($id);
        if ('bank_transfer' !== $payment->provider) {
            throw new \InvalidArgumentException('Nur Vorkasse kann manuell bestätigt werden.');
        }
        $this->apply($payment->provider, new PaymentEvent('manual:paid:'.$id, $id, $payment->reference, PaymentStatus::Paid, $payment->money, testMode: $payment->testMode));
    }

    public function refund(int $id, Money $money, string $operation): RefundResult
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $operation)) {
            throw new \InvalidArgumentException('Invalid refund operation.');
        }

        if ($this->connection->isTransactionActive() && 'stripe' === $this->repository->find($id)->provider) {
            throw new \LogicException('Stripe refunds require an independently committed operation.');
        }

        // Reserve under the payment lock and commit BEFORE any money can move.
        $this->connection->transactional(
            function () use ($id, $money, $operation): void {
                $payment = $this->repository->find($id, true);
                $existing = $this->connection->fetchAssociative('SELECT * FROM tl_nw_payment_refund WHERE operation_token = ? FOR UPDATE', [$operation]);
                if (false !== $existing) {
                    if ((int) $existing['payment_id'] !== $id || (int) $existing['amount'] !== $money->cents || $money->currency !== $payment->money->currency) {
                        throw new \InvalidArgumentException('Refund retry does not match.');
                    }

                    return;
                }
                $pending = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_nw_payment_refund WHERE payment_id = ? AND succeeded = 0', [$id]);
                if ($pending > 0 || !\in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true) || $money->currency !== $payment->money->currency || $money->cents < 1 || $money->cents > $payment->money->cents - $payment->refundedCents) {
                    throw new \InvalidArgumentException('Erstattungsbetrag oder Zahlungsstatus ungültig.');
                }
                $this->connection->insert('tl_nw_payment_refund', ['tstamp' => time(), 'created_at' => time(), 'payment_id' => $id, 'operation_token' => $operation, 'amount' => $money->cents, 'base_refunded_amount' => $payment->refundedCents, 'provider_reference' => '', 'succeeded' => 0]);
            },
        );

        return $this->connection->transactional(
            function () use ($id, $money, $operation): RefundResult {
                $payment = $this->repository->find($id, true);
                $existing = $this->connection->fetchAssociative('SELECT * FROM tl_nw_payment_refund WHERE operation_token = ? FOR UPDATE', [$operation]);
                if (false === $existing) {
                    throw new \RuntimeException('Refund operation missing.');
                }
                if ('' !== $existing['provider_reference']) {
                    return new RefundResult((string) $existing['provider_reference'], (bool) $existing['succeeded']);
                }
                $result = $this->provider($payment->provider)->refund($payment, $money, $operation);
                $this->connection->update('tl_nw_payment_refund', ['provider_reference' => $result->reference, 'succeeded' => $result->succeeded ? 1 : 0], ['operation_token' => $operation]);
                if ($result->succeeded) {
                    $total = (int) $existing['base_refunded_amount'] + $money->cents;
                    $this->apply($payment->provider, new PaymentEvent('refund:'.$result->reference, $id, $payment->reference, $total === $payment->money->cents ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded, $payment->money, $total, $payment->testMode));
                }

                return $result;
            },
        );
    }

    public function reconcile(): void
    {
        $ids = $this->connection->fetchFirstColumn("SELECT id FROM tl_nw_payment WHERE status IN ('open', 'pending') AND created_at < ? AND provider_reference <> '' AND provider <> 'bank_transfer' ORDER BY checked_at, id LIMIT 100", [time() - 600]);

        foreach ($ids as $id) {
            try {
                $this->connection->update('tl_nw_payment', ['checked_at' => time()], ['id' => (int) $id]);
                $payment = $this->repository->find((int) $id);
                $provider = $this->provider($payment->provider);
                $status = $provider->fetchStatus($payment);
                if ($status !== $payment->status) {
                    $event = $provider instanceof PaymentSnapshotProviderInterface ? $provider->fetchSnapshot($payment) : new PaymentEvent('fetch:'.$id.':'.$status->value, (int) $id, $payment->reference, $status, $payment->money, testMode: $payment->testMode);
                    $this->apply($payment->provider, $event);
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Payment reconciliation deferred.', ['payment_id' => (int) $id, 'failure_type' => $exception::class]);
            }
        }
    }
}
