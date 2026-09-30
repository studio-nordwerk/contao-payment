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
use Nordwerk\PaymentBundle\Settings\PaymentSettings;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Request;

final readonly class StripeProvider implements PaymentProviderInterface, PaymentSnapshotProviderInterface
{
    public const EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired', 'charge.refunded', 'refund.created', 'refund.updated', 'refund.failed'];

    public function __construct(
        private StripeClientInterface $client,
        private PaymentSettings $settings,
    ) {
    }

    public function createCheckout(PaymentRequest $request): CheckoutResult
    {
        $payment = $request->payment;
        // Hosted Checkout enables dynamic/automatic payment methods by default.
        // automatic_payment_methods belongs to PaymentIntent, not Session creation.
        $session = $this->client->request(
            'post',
            '/v1/checkout/sessions',
            [
                'mode' => 'payment',
                'success_url' => $request->returnUrl,
                'cancel_url' => $request->returnUrl.'?canceled=1',
                'client_reference_id' => (string) $payment->id,
                'metadata' => ['payment_id' => (string) $payment->id],
                'payment_intent_data' => ['metadata' => ['payment_id' => (string) $payment->id]],
                'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower($payment->money->currency), 'unit_amount' => $payment->money->cents, 'product_data' => ['name' => $request->description]]]],
            ],
            'checkout:'.$payment->id,
        );
        if ((bool) ($session['livemode'] ?? true) === $payment->testMode) {
            throw new \RuntimeException('Stripe response mode mismatch.');
        }

        return new CheckoutResult((string) $session['id'], (string) $session['url']);
    }

    public function resumeCheckout(PaymentRequest $request): CheckoutResult
    {
        $payment = $request->payment;
        $session = $this->client->request('get', '/v1/checkout/sessions/'.rawurlencode($payment->reference));
        $event = $this->sessionEvent('resume:'.$payment->id, $session, PaymentStatus::Open);
        if ($event->paymentId !== $payment->id || $event->reference !== $payment->reference || !$event->money->equals($payment->money) || $event->testMode !== $payment->testMode) {
            throw new \InvalidArgumentException('Stripe session does not match payment.');
        }

        return new CheckoutResult($payment->reference, 'open' === ($session['status'] ?? '') ? (string) $session['url'] : $request->returnUrl);
    }

    public function parseWebhook(Request $request): PaymentEvent
    {
        $secret = $this->settings->webhookSecret();
        if ('' === $secret) {
            throw new \InvalidArgumentException('Webhook secret is missing.');
        }
        $signature = ($request->headers->get('Stripe-Signature') ?? '');
        if (!preg_match('/(?:^|,)t=([0-9]+)(?:,|$)/', $signature, $match) || abs(time() - (int) $match[1]) > 300) {
            throw SignatureVerificationException::factory('Webhook timestamp outside tolerance.', null, $signature);
        }
        $event = Webhook::constructEvent($request->getContent(), $signature, $secret, 300)->toArray();
        if ((bool) ($event['livemode'] ?? true) === $this->settings->testMode()) {
            throw new \InvalidArgumentException('Webhook mode mismatch.');
        }
        $type = (string) $event['type'];
        if (!\in_array($type, self::EVENTS, true)) {
            throw new UnsupportedEventException();
        }
        $object = $event['data']['object'];
        if (str_starts_with($type, 'refund.')) {
            return $this->refundEvent((string) $event['id'], (string) $object['id']);
        }
        if ('charge.refunded' === $type) {
            $sessions = $this->client->request('get', '/v1/checkout/sessions', ['payment_intent' => $object['payment_intent'], 'limit' => 1]);
            $session = $sessions['data'][0] ?? null;
            if (!\is_array($session)) {
                throw new \InvalidArgumentException('Refund has no checkout session.');
            }
            $refunded = (int) $object['amount_refunded'];
            $status = $refunded >= (int) $object['amount'] ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
            if ((int) $session['amount_total'] !== (int) $object['amount'] || $session['currency'] !== $object['currency']) {
                throw new \InvalidArgumentException('Refund amount mismatch.');
            }

            return $this->sessionEvent((string) $event['id'], $session, $status, $refunded);
        }
        $status = match ($type) {
            'checkout.session.expired' => PaymentStatus::Expired,
            'checkout.session.async_payment_failed' => PaymentStatus::Failed,
            'checkout.session.async_payment_succeeded' => PaymentStatus::Paid,
            default => 'paid' === ($object['payment_status'] ?? '') ? PaymentStatus::Paid : PaymentStatus::Pending,
        };

        return $this->sessionEvent((string) $event['id'], $object, $status);
    }

    public function refund(Payment $payment, Money $money, string $operation): RefundResult
    {
        $session = $this->client->request('get', '/v1/checkout/sessions/'.rawurlencode($payment->reference));
        $refund = $this->client->request('post', '/v1/refunds', ['payment_intent' => $session['payment_intent'], 'amount' => $money->cents, 'metadata' => ['payment_id' => (string) $payment->id, 'operation_token' => $operation]], 'refund:'.$operation);

        return new RefundResult((string) $refund['id'], 'succeeded' === $refund['status'], RefundStatus::from((string) $refund['status']));
    }

    public function refundEvent(string $eventId, string $reference): PaymentEvent
    {
        // Read current provider state so delayed events cannot revive a failed refund.
        $refund = $this->client->request('get', '/v1/refunds/'.rawurlencode($reference));
        $sessions = $this->client->request('get', '/v1/checkout/sessions', ['payment_intent' => $refund['payment_intent'], 'limit' => 1]);
        $session = $sessions['data'][0] ?? throw new \InvalidArgumentException('Refund has no checkout session.');
        $session = $this->client->request('get', '/v1/checkout/sessions/'.rawurlencode((string) $session['id']), ['expand' => ['payment_intent.latest_charge']]);
        if ($reference !== $refund['id'] || strtoupper((string) $refund['currency']) !== strtoupper((string) $session['currency']) || (int) $refund['amount'] < 1 || (int) $refund['amount'] > (int) $session['amount_total']) {
            throw new \InvalidArgumentException('Refund does not match session.');
        }
        $total = (int) ($session['payment_intent']['latest_charge']['amount_refunded'] ?? 0);
        $status = $total > 0 ? ($total === (int) $session['amount_total'] ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded) : PaymentStatus::Paid;
        $event = $this->sessionEvent($eventId, $session, $status, $total);
        $refundStatus = RefundStatus::from((string) $refund['status']);

        return new PaymentEvent($event->eventId, $event->paymentId, $event->reference, $event->status, $event->money, $event->refundedCents, $event->testMode, new RefundResult($reference, RefundStatus::Succeeded === $refundStatus, $refundStatus), (string) ($refund['metadata']['operation_token'] ?? ''), (int) $refund['amount']);
    }

    public function fetchStatus(Payment $payment): PaymentStatus
    {
        return $this->fetchSnapshot($payment)->status;
    }

    public function fetchSnapshot(Payment $payment): PaymentEvent
    {
        $session = $this->client->request('get', '/v1/checkout/sessions/'.rawurlencode($payment->reference), ['expand' => ['payment_intent.latest_charge']]);
        $intent = $session['payment_intent'] ?? null;
        $charge = \is_array($intent) ? ($intent['latest_charge'] ?? null) : null;
        $refunded = \is_array($charge) ? (int) ($charge['amount_refunded'] ?? 0) : 0;
        $status = match (true) {
            $refunded > 0 && $refunded === $payment->money->cents => PaymentStatus::Refunded,
            $refunded > 0 => PaymentStatus::PartiallyRefunded,
            'paid' === ($session['payment_status'] ?? '') => PaymentStatus::Paid,
            'expired' === ($session['status'] ?? '') => PaymentStatus::Expired,
            'complete' === ($session['status'] ?? '') && \is_array($intent) && ('canceled' === ($intent['status'] ?? '') || ('requires_payment_method' === ($intent['status'] ?? '') && null !== ($intent['last_payment_error'] ?? null)) || (\is_array($charge) && 'failed' === ($charge['status'] ?? ''))) => PaymentStatus::Failed,
            'complete' === ($session['status'] ?? '') => PaymentStatus::Pending,
            default => PaymentStatus::Open,
        };
        $event = $this->sessionEvent('fetch:'.$payment->id.':'.$status->value.':'.$refunded, $session, $status, $refunded);
        if ($event->paymentId !== $payment->id || $event->reference !== $payment->reference || !$event->money->equals($payment->money) || $event->testMode !== $payment->testMode) {
            throw new \InvalidArgumentException('Stripe status does not match payment.');
        }

        return $event;
    }

    public function registerWebhook(string $url): void
    {
        if (!$this->settings->testMode() && !str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException('Live-Webhooks benötigen HTTPS.');
        }
        $endpoint = $this->client->request('post', '/v1/webhook_endpoints', ['url' => $url, 'enabled_events' => self::EVENTS]);
        if ((bool) ($endpoint['livemode'] ?? true) === $this->settings->testMode()) {
            throw new \RuntimeException('Webhook-Testmodus stimmt nicht überein.');
        }
        $this->settings->saveWebhook((string) $endpoint['secret'], (string) $endpoint['id']);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function sessionEvent(string $id, array $session, PaymentStatus $status, int $refunded = 0): PaymentEvent
    {
        return new PaymentEvent($id, (int) ($session['metadata']['payment_id'] ?? 0), (string) $session['id'], $status, new Money((int) $session['amount_total'], strtoupper((string) $session['currency'])), $refunded, !(bool) $session['livemode']);
    }
}
