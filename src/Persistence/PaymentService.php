<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Persistence;

use Doctrine\DBAL\Connection;
use Nordwerk\PaymentBundle\Domain\CheckoutResult;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\PayableResolverInterface;
use Nordwerk\PaymentBundle\Domain\Payment;
use Nordwerk\PaymentBundle\Domain\PaymentEvent;
use Nordwerk\PaymentBundle\Domain\PaymentProviderInterface;
use Nordwerk\PaymentBundle\Domain\PaymentRequest;
use Nordwerk\PaymentBundle\Domain\PaymentSnapshotProviderInterface;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;
use Nordwerk\PaymentBundle\Domain\RefundRejectedException;
use Nordwerk\PaymentBundle\Domain\RefundResult;
use Nordwerk\PaymentBundle\Domain\RefundStatus;
use Nordwerk\PaymentBundle\Domain\ResumableCheckoutInterface;
use Nordwerk\PaymentBundle\Provider\BankTransferProvider;
use Nordwerk\PaymentBundle\Provider\MollieProvider;
use Nordwerk\PaymentBundle\Provider\OnSiteProvider;
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
        private OnSiteProvider $onSite = new OnSiteProvider(),
        private MollieProvider|null $mollie = null,
    ) {
    }

    /**
     * Payment on site is listed last; consumers offer it only where the customer
     * comes by, e.g. for pickup.
     *
     * @return array<string, string>
     */
    public function methods(): array
    {
        $methods = [];

        foreach (['bank_transfer' => 'Vorkasse per Überweisung', 'stripe' => 'Online bezahlen (Stripe)', 'mollie' => 'Online bezahlen (Mollie)', 'on_site' => 'Bezahlung vor Ort'] as $id => $label) {
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
            'mollie' => $this->mollie ?? throw new \InvalidArgumentException('Unknown payment provider.'),
            'on_site' => $this->onSite,
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
                // One payment per payable: a payment of the other mode must not be reused (a
                // paid test payment would count as paid in live mode), and none is created next
                // to it.
                if (\in_array($provider, ['stripe', 'mollie'], true) && $payment->testMode !== $this->settings->testMode()) {
                    throw new \InvalidArgumentException('Die vorhandene Zahlung wurde im '.($payment->testMode ? 'Test' : 'Live').'modus angelegt und passt nicht zum aktuellen Modus. Bitte eine neue Zahlung für diesen Bezug anlegen lassen.');
                }
                if (PaymentStatus::Open !== $payment->status) {
                    return new CheckoutResult($payment->reference, $origin.'/_nw/payment/return/'.$payment->token);
                }
                if ('' !== $payment->reference) {
                    $resumable = $this->provider($provider);

                    return $resumable instanceof ResumableCheckoutInterface ? $resumable->resumeCheckout(new PaymentRequest($payment, $origin.'/_nw/payment/return/'.$payment->token, $description)) : new CheckoutResult($payment->reference);
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
                if (null !== $event->refund) {
                    $operation = $this->connection->fetchAssociative('SELECT * FROM tl_nw_payment_refund WHERE payment_id = ? AND (provider_reference = ? OR operation_token = ?) FOR UPDATE', [$payment->id, $event->refund->reference, $event->refundOperation]);
                    if (false !== $operation) {
                        if ((int) $operation['amount'] !== $event->refundAmount || ('' !== $operation['provider_reference'] && $operation['provider_reference'] !== $event->refund->reference)) {
                            throw new \InvalidArgumentException('Refund event does not match operation.');
                        }
                        $this->connection->update('tl_nw_payment_refund', ['provider_reference' => $event->refund->reference, 'succeeded' => $event->refund->succeeded ? 1 : 0, 'status' => $event->refund->status->value, 'tstamp' => time()], ['id' => $operation['id']]);
                    }
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
        if (!\in_array($payment->provider, ['bank_transfer', 'on_site'], true)) {
            throw new \InvalidArgumentException('Nur Vorkasse und Bezahlung vor Ort können manuell bestätigt werden.');
        }
        $this->apply($payment->provider, new PaymentEvent('manual:paid:'.$id, $id, $payment->reference, PaymentStatus::Paid, $payment->money, testMode: $payment->testMode));
    }

    public function refund(int $id, Money $money, string $operation): RefundResult
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $operation)) {
            throw new \InvalidArgumentException('Invalid refund operation.');
        }

        if ($this->connection->isTransactionActive() && \in_array($this->repository->find($id)->provider, ['stripe', 'mollie'], true)) {
            throw new \LogicException('Stripe and Mollie refunds require an independently committed operation.');
        }

        // Read before the transaction: a plain read inside it would fix the snapshot,
        // and the locking reads below would then fail on rows that other requests
        // changed meanwhile.
        $testMode = $this->settings->testMode();
        // Reserve under the payment lock and commit BEFORE any money can move.
        $this->connection->transactional(
            function () use ($id, $money, $operation, $testMode): void {
                $payment = $this->repository->find($id, true);
                // The key of the other mode no longer exists, so such a payment cannot be
                // refunded here.
                if (\in_array($payment->provider, ['stripe', 'mollie'], true) && $payment->testMode !== $testMode) {
                    throw new \InvalidArgumentException('Die Zahlung wurde im '.($payment->testMode ? 'Test' : 'Live').'modus angelegt; im aktuellen Modus lässt sie sich nicht erstatten. Erstattung im Dashboard des Anbieters oder nach Rückkehr in den früheren Modus.');
                }
                $existing = $this->connection->fetchAssociative('SELECT * FROM tl_nw_payment_refund WHERE operation_token = ? FOR UPDATE', [$operation]);
                if (false !== $existing) {
                    if ((int) $existing['payment_id'] !== $id || (int) $existing['amount'] !== $money->cents || $money->currency !== $payment->money->currency) {
                        throw new \InvalidArgumentException('Refund retry does not match.');
                    }

                    return;
                }
                $pending = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_nw_payment_refund WHERE payment_id = ? AND succeeded = 0 AND status IN (\'pending\', \'requires_action\')', [$id]);
                if ($pending > 0 || !\in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true) || $money->currency !== $payment->money->currency || $money->cents < 1 || $money->cents > $payment->money->cents - $payment->refundedCents) {
                    throw new \InvalidArgumentException('Erstattungsbetrag oder Zahlungsstatus ungültig.');
                }
                $this->connection->insert('tl_nw_payment_refund', ['tstamp' => time(), 'created_at' => time(), 'payment_id' => $id, 'operation_token' => $operation, 'amount' => $money->cents, 'base_refunded_amount' => $payment->refundedCents, 'provider_reference' => '', 'succeeded' => 0]);
            },
        );

        $rejected = null;
        $result = $this->connection->transactional(
            function () use ($id, $money, $operation, &$rejected): RefundResult|null {
                $payment = $this->repository->find($id, true);
                $existing = $this->connection->fetchAssociative('SELECT * FROM tl_nw_payment_refund WHERE operation_token = ? FOR UPDATE', [$operation]);
                if (false === $existing) {
                    throw new \RuntimeException('Refund operation missing.');
                }
                if ('' !== $existing['provider_reference']) {
                    return new RefundResult((string) $existing['provider_reference'], (bool) $existing['succeeded'], (bool) $existing['succeeded'] ? RefundStatus::Succeeded : RefundStatus::from((string) $existing['status']));
                }
                if ('failed' === $existing['status']) {
                    $rejected = new \InvalidArgumentException('Erstattung wurde vom Anbieter abgelehnt: '.$existing['reason']);

                    return null;
                }

                try {
                    $result = $this->provider($payment->provider)->refund($payment, $money, $operation);
                } catch (RefundRejectedException $exception) {
                    // The provider definitively refused the request: keep that, free the reservation.
                    $this->connection->update('tl_nw_payment_refund', ['status' => RefundStatus::Failed->value, 'reason' => mb_substr($exception->getMessage(), 0, 255), 'tstamp' => time()], ['operation_token' => $operation]);
                    $rejected = new \InvalidArgumentException('Erstattung wurde vom Anbieter abgelehnt.', 0, $exception);

                    return null;
                }
                $this->connection->update('tl_nw_payment_refund', ['provider_reference' => $result->reference, 'succeeded' => $result->succeeded ? 1 : 0, 'status' => $result->status->value], ['operation_token' => $operation]);
                if ($result->succeeded) {
                    $this->apply($payment->provider, $this->succeededRefundEvent($payment, $result, (int) $existing['base_refunded_amount'] + $money->cents));
                }

                return $result;
            },
        );
        // Thrown after the commit, so the rejection stays recorded.
        if (null !== $rejected) {
            throw $rejected;
        }

        return $result ?? throw new \RuntimeException('Refund operation missing.');
    }

    public function reconcile(): void
    {
        $refunds = $this->connection->fetchAllAssociative("SELECT r.* FROM tl_nw_payment_refund r INNER JOIN tl_nw_payment p ON p.id = r.payment_id WHERE p.provider IN ('stripe', 'mollie') AND r.succeeded = 0 AND r.status IN ('pending', 'requires_action') ORDER BY r.checked_at, r.id LIMIT 100");

        foreach ($refunds as $refund) {
            try {
                // Whatever happens next, the others get their turn first the next time.
                $this->connection->update('tl_nw_payment_refund', ['checked_at' => time()], ['id' => (int) $refund['id']]);
                if ('' === $refund['provider_reference']) {
                    $payment = $this->repository->find((int) $refund['payment_id']);
                    $this->refund($payment->id, new Money((int) $refund['amount'], $payment->money->currency), (string) $refund['operation_token']);
                } else {
                    $payment = $this->repository->find((int) $refund['payment_id']);
                    $eventId = 'refund-fetch:'.$refund['id'].':'.bin2hex(random_bytes(16));
                    $event = 'mollie' === $payment->provider
                        ? ($this->mollie ?? throw new \LogicException('Mollie is not available.'))->refundEvent($eventId, $payment, (string) $refund['provider_reference'], (int) $refund['amount'], (string) $refund['operation_token'])
                        : $this->stripe->refundEvent($eventId, (string) $refund['provider_reference']);
                    $this->apply($payment->provider, $event);
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Refund reconciliation deferred.', ['payment_id' => (int) $refund['payment_id'], 'failure_type' => $exception::class]);
            }
        }

        $ids = $this->connection->fetchFirstColumn("SELECT id FROM tl_nw_payment WHERE status IN ('open', 'pending') AND created_at < ? AND provider_reference <> '' AND provider NOT IN ('bank_transfer', 'on_site') ORDER BY checked_at, id LIMIT 100", [time() - 600]);

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

        $this->reconcileMolliePaid();
    }

    /**
     * Mollie payments that are still open or pending are read from the API when the
     * customer returns, so shops without a reachable webhook (local, behind a
     * firewall) do not wait for the cron. The state comes from Mollie, never from the
     * browser; at most every five seconds per payment.
     */
    public function refreshFromProvider(Payment $payment): Payment
    {
        if ('mollie' !== $payment->provider || null === $this->mollie || '' === $payment->reference || !\in_array($payment->status, [PaymentStatus::Open, PaymentStatus::Pending], true)) {
            return $payment;
        }
        $now = time();
        // Claims the slot atomically, so parallel reloads do not all call the API.
        if (1 !== $this->connection->executeStatement('UPDATE tl_nw_payment SET checked_at = ? WHERE id = ? AND checked_at <= ?', [$now, $payment->id, $now - 5])) {
            return $payment;
        }

        try {
            $this->apply('mollie', $this->mollie->fetchSnapshot($payment));
        } catch (\Throwable $exception) {
            $this->logger->error('Mollie status check on return deferred.', ['payment_id' => $payment->id, 'failure_type' => $exception::class]);

            return $payment;
        }

        return $this->repository->find($payment->id);
    }

    /**
     * Mollie confirms the cumulative refunded amount itself: an adopted refund may be
     * one of several, and refunds from the dashboard are not in our own sum. Stripe,
     * and Mollie if it cannot be asked right now, use the sum from the reservation.
     */
    private function succeededRefundEvent(Payment $payment, RefundResult $result, int $total): PaymentEvent
    {
        if ('mollie' === $payment->provider && null !== $this->mollie) {
            try {
                return $this->mollie->fetchSnapshot($payment);
            } catch (\Throwable $exception) {
                $this->logger->error('Mollie refund total could not be confirmed.', ['payment_id' => $payment->id, 'failure_type' => $exception::class]);
            }
        }

        return new PaymentEvent('refund:'.$result->reference, $payment->id, $payment->reference, $total === $payment->money->cents ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded, $payment->money, $total, $payment->testMode);
    }

    /**
     * Refunds made in the Mollie dashboard arrive without a webhook when none is
     * reachable. A small rotating set of paid Mollie payments is therefore read again
     * from time to time.
     */
    private function reconcileMolliePaid(): void
    {
        if (null === $this->mollie) {
            return;
        }
        $ids = $this->connection->fetchFirstColumn("SELECT id FROM tl_nw_payment WHERE provider = 'mollie' AND status IN ('paid', 'partially_refunded') AND provider_reference <> '' AND test_mode = ? AND created_at > ? AND checked_at < ? ORDER BY checked_at, id LIMIT 20", [$this->settings->testMode() ? 1 : 0, time() - 180 * 86400, time() - 600]);

        foreach ($ids as $id) {
            try {
                $this->connection->update('tl_nw_payment', ['checked_at' => time()], ['id' => (int) $id]);
                $this->apply('mollie', $this->mollie->fetchSnapshot($this->repository->find((int) $id)));
            } catch (\Throwable $exception) {
                $this->logger->error('Mollie refund check deferred.', ['payment_id' => (int) $id, 'failure_type' => $exception::class]);
            }
        }
    }

    /**
     * Whether a payment of this provider has this provider reference (indexed).
     */
    public function hasReference(string $provider, string $reference): bool
    {
        return false !== $this->connection->fetchOne('SELECT id FROM tl_nw_payment WHERE provider = ? AND provider_reference = ? LIMIT 1', [$provider, $reference]);
    }
}
