<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Provider;

use Nordwerk\PaymentBundle\Domain\CheckoutResult;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Domain\Payment;
use Nordwerk\PaymentBundle\Domain\PaymentEvent;
use Nordwerk\PaymentBundle\Domain\PaymentProviderInterface;
use Nordwerk\PaymentBundle\Domain\PaymentRequest;
use Nordwerk\PaymentBundle\Domain\PaymentSnapshotProviderInterface;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;
use Nordwerk\PaymentBundle\Domain\RefundResult;
use Nordwerk\PaymentBundle\Domain\RefundStatus;
use Nordwerk\PaymentBundle\Domain\ResumableCheckoutInterface;
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Mollie Payments API v2 with the hosted Mollie checkout. Mollie webhooks carry no
 * signature, only a payment id; the state is therefore always read from the API with
 * our own key and compared with the stored payment (PaymentService::apply()).
 */
final readonly class MollieProvider implements PaymentProviderInterface, PaymentSnapshotProviderInterface, ResumableCheckoutInterface
{
    public const PAYMENT_ID = '/^tr_[A-Za-z0-9]{4,64}$/D';
    private const REFUND_ID = '/^re_[A-Za-z0-9]{4,64}$/D';

    public function __construct(
        private MollieClientInterface $client,
        private PaymentSettings $settings,
        private UrlGeneratorInterface $router,
    ) {
    }

    /**
     * Mollie rejects a webhookUrl it cannot reach: false for local and private hosts.
     */
    public static function isPublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = \is_array($parts) ? strtolower(trim($parts['host'] ?? '', '[]')) : '';

        return \in_array($scheme, ['http', 'https'], true) && self::publicHost($host);
    }

    public function createCheckout(PaymentRequest $request): CheckoutResult
    {
        $payment = $request->payment;
        $body = [
            'amount' => MollieAmount::format($payment->money),
            'description' => $request->description,
            'redirectUrl' => $request->returnUrl,
            'cancelUrl' => $request->returnUrl.'?canceled=1',
            'metadata' => ['payment_id' => (string) $payment->id],
        ];
        // From the router, so installations in a subdirectory get their base path.
        $webhook = $this->router->generate('nw_payment_webhook', ['provider' => 'mollie'], UrlGeneratorInterface::ABSOLUTE_URL);
        if (self::isPublicUrl($webhook)) {
            $body['webhookUrl'] = $webhook;
        }
        $mollie = $this->client->request('post', '/payments', $body, 'checkout:'.$payment->id);
        $event = $this->paymentEvent($mollie);
        if ($event->testMode !== $payment->testMode || $event->paymentId !== $payment->id || !$event->money->equals($payment->money)) {
            throw new \RuntimeException('Mollie response does not match payment or mode.');
        }
        $href = $mollie['_links']['checkout']['href'] ?? null;
        if (!\is_string($href) || '' === $href) {
            throw new \RuntimeException('Mollie returned no checkout link.');
        }

        return new CheckoutResult($event->reference, $href);
    }

    public function resumeCheckout(PaymentRequest $request): CheckoutResult
    {
        $payment = $request->payment;
        $mollie = $this->fetch($payment);
        $event = $this->matching($payment, $mollie);
        $href = $mollie['_links']['checkout']['href'] ?? null;

        return new CheckoutResult($payment->reference, PaymentStatus::Open === $event->status && \is_string($href) && '' !== $href ? $href : $request->returnUrl);
    }

    public function parseWebhook(Request $request): PaymentEvent
    {
        $id = $request->request->all()['id'] ?? null;
        if (!\is_string($id) || 1 !== preg_match(self::PAYMENT_ID, $id)) {
            throw new \InvalidArgumentException('Invalid Mollie payment id.');
        }
        $mollie = $this->client->request('get', '/payments/'.$id);
        $event = $this->paymentEvent($mollie);
        if ($event->reference !== $id || $event->testMode !== $this->settings->testMode()) {
            throw new \InvalidArgumentException('Webhook payment or mode mismatch.');
        }

        return $event;
    }

    public function refund(Payment $payment, Money $money, string $operation): RefundResult
    {
        // The idempotency key of Mollie only lives for a limited time. If an earlier
        // attempt got through but its answer was lost, the refund is already there:
        // adopt it. When the list cannot be read the exception stops the replay.
        $adopted = $this->existingRefund($payment, $money, $operation);
        if (null !== $adopted) {
            return $adopted;
        }
        $refund = $this->client->request(
            'post',
            '/payments/'.rawurlencode($payment->reference).'/refunds',
            ['amount' => MollieAmount::format($money), 'description' => 'Erstattung', 'metadata' => ['payment_id' => (string) $payment->id, 'operation_token' => $operation]],
            'refund:'.$operation,
        );
        $status = self::refundStatus($refund);
        $id = (string) ($refund['id'] ?? '');
        if (1 !== preg_match(self::REFUND_ID, $id)) {
            throw new \RuntimeException('Mollie returned no refund id.');
        }

        return new RefundResult($id, RefundStatus::Succeeded === $status, $status);
    }

    /**
     * Current state of one refund together with its payment, for the cron reconciliation.
     */
    public function refundEvent(string $eventId, Payment $payment, string $refundId): PaymentEvent
    {
        if (1 !== preg_match(self::REFUND_ID, $refundId)) {
            throw new \InvalidArgumentException('Invalid Mollie refund id.');
        }
        $refund = $this->client->request('get', '/payments/'.rawurlencode($payment->reference).'/refunds/'.$refundId);
        $event = $this->matching($payment, $this->fetch($payment));
        $amount = MollieAmount::parse($refund['amount'] ?? null);
        if (($refund['id'] ?? '') !== $refundId || $amount->currency !== $payment->money->currency || $amount->cents < 1 || $amount->cents > $payment->money->cents) {
            throw new \InvalidArgumentException('Refund does not match payment.');
        }
        $status = self::refundStatus($refund);

        return new PaymentEvent($eventId, $event->paymentId, $event->reference, $event->status, $event->money, $event->refundedCents, $event->testMode, new RefundResult($refundId, RefundStatus::Succeeded === $status, $status), (string) ($refund['metadata']['operation_token'] ?? ''), $amount->cents);
    }

    public function fetchStatus(Payment $payment): PaymentStatus
    {
        return $this->fetchSnapshot($payment)->status;
    }

    public function fetchSnapshot(Payment $payment): PaymentEvent
    {
        return $this->matching($payment, $this->fetch($payment));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(Payment $payment): array
    {
        if (1 !== preg_match(self::PAYMENT_ID, $payment->reference)) {
            throw new \InvalidArgumentException('Payment has no Mollie reference.');
        }

        return $this->client->request('get', '/payments/'.$payment->reference);
    }

    /**
     * @param array<string, mixed> $mollie
     */
    private function matching(Payment $payment, array $mollie): PaymentEvent
    {
        $event = $this->paymentEvent($mollie);
        if ($event->paymentId !== $payment->id || $event->reference !== $payment->reference || !$event->money->equals($payment->money) || $event->testMode !== $payment->testMode) {
            throw new \InvalidArgumentException('Mollie payment does not match stored payment.');
        }

        return $event;
    }

    /**
     * The event id is derived from the state, so a repeated webhook, the return page
     * and the cron all produce the same id and the duplicate detection applies.
     *
     * @param array<string, mixed> $mollie
     */
    private function paymentEvent(array $mollie): PaymentEvent
    {
        $id = (string) ($mollie['id'] ?? '');
        if (1 !== preg_match(self::PAYMENT_ID, $id) || !\in_array($mollie['mode'] ?? null, ['test', 'live'], true)) {
            throw new \InvalidArgumentException('Invalid Mollie payment.');
        }
        $money = MollieAmount::parse($mollie['amount'] ?? null);
        $refunded = 0;
        $status = match ($mollie['status'] ?? null) {
            'open' => PaymentStatus::Open,
            'pending', 'authorized' => PaymentStatus::Pending,
            'paid' => PaymentStatus::Paid,
            // Canceled by the customer: no money moved.
            'canceled', 'expired' => PaymentStatus::Expired,
            'failed' => PaymentStatus::Failed,
            default => throw new \InvalidArgumentException('Unknown Mollie payment status.'),
        };
        if (PaymentStatus::Paid === $status) {
            // amountRefunded is not used: Mollie does not say whether it counts refunds that are
            // still queued or pending. Only refunds in status "refunded" count as money back.
            $listed = isset($mollie['amountRefunded']) ? MollieAmount::parse($mollie['amountRefunded'])->cents : 0;
            if ($listed > 0 || isset($mollie['_links']['refunds'])) {
                $refunded = $this->refundedCents($id, $money);
            }
            if ($refunded > 0) {
                $status = $refunded === $money->cents ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
            }
        }
        $paymentId = $mollie['metadata']['payment_id'] ?? 0;

        return new PaymentEvent('mollie:'.$id.':'.$status->value.':'.$refunded, \is_int($paymentId) || (\is_string($paymentId) && ctype_digit($paymentId)) ? (int) $paymentId : 0, $id, $status, $money, $refunded, 'test' === $mollie['mode']);
    }

    /**
     * Any failure here is "unclear" and therefore a RuntimeException: an
     * InvalidArgumentException would be read as a definitive rejection of the refund.
     */
    private function existingRefund(Payment $payment, Money $money, string $operation): RefundResult|null
    {
        try {
            foreach ($this->listRefunds($payment->reference) as $existing) {
                if (($existing['metadata']['operation_token'] ?? null) !== $operation) {
                    continue;
                }
                if (($existing['metadata']['payment_id'] ?? null) !== (string) $payment->id || !MollieAmount::parse($existing['amount'] ?? null)->equals($money)) {
                    throw new \RuntimeException('A Mollie refund with this operation exists but does not match.');
                }
                $status = self::refundStatus($existing);

                return new RefundResult((string) $existing['id'], RefundStatus::Succeeded === $status, $status);
            }
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('Mollie refunds could not be read.', 0, $exception);
        }

        return null;
    }

    /**
     * Sum of the refunds with status "refunded" of one payment.
     */
    private function refundedCents(string $id, Money $total): int
    {
        $sum = 0;

        foreach ($this->listRefunds($id) as $refund) {
            $amount = MollieAmount::parse($refund['amount'] ?? null);
            if ($amount->currency !== $total->currency) {
                throw new \InvalidArgumentException('Mollie refund currency mismatch.');
            }
            if ('refunded' === ($refund['status'] ?? null)) {
                $sum += $amount->cents;
            }
        }
        if ($sum > $total->cents) {
            throw new \InvalidArgumentException('Mollie refunds exceed the payment amount.');
        }

        return $sum;
    }

    /**
     * All refunds of a payment, page by page.
     *
     * @return list<array<string, mixed>>
     */
    private function listRefunds(string $id): array
    {
        $all = [];
        $from = null;

        for ($page = 0; $page < 20; ++$page) {
            $list = $this->client->request('get', '/payments/'.rawurlencode($id).'/refunds', ['limit' => 250] + (null === $from ? [] : ['from' => $from]));
            $refunds = $list['_embedded']['refunds'] ?? [];
            if (!\is_array($refunds)) {
                throw new \InvalidArgumentException('Invalid Mollie refund list.');
            }

            foreach ($refunds as $refund) {
                if (!\is_array($refund) || !\is_string($refund['id'] ?? null)) {
                    throw new \InvalidArgumentException('Invalid Mollie refund.');
                }
                $all[] = $refund;
                $from = $refund['id'];
            }
            if (!isset($list['_links']['next']['href']) || [] === $refunds) {
                return $all;
            }
        }

        throw new \InvalidArgumentException('Too many Mollie refund pages.');
    }

    /**
     * @param array<string, mixed> $refund
     */
    private static function refundStatus(array $refund): RefundStatus
    {
        return match ($refund['status'] ?? null) {
            'queued', 'pending', 'processing' => RefundStatus::Pending,
            'refunded' => RefundStatus::Succeeded,
            'failed' => RefundStatus::Failed,
            'canceled' => RefundStatus::Canceled,
            default => throw new \UnexpectedValueException('Unknown Mollie refund status.'),
        };
    }

    private static function publicHost(string $host): bool
    {
        if ('' === $host || 'localhost' === $host || (!str_contains($host, '.') && !str_contains($host, ':'))) {
            return false;
        }

        foreach (['.localhost', '.test', '.local', '.internal', '.localdomain'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
