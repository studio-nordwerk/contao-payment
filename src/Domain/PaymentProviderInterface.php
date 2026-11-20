<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Domain;

use Symfony\Component\HttpFoundation\Request;

/**
 * One payment provider. Every service implementing this interface is registered (tag
 * nordwerk.payment.provider): PaymentService offers it in the checkout as soon as its
 * settings section (Settings\ProviderSettingsInterface with the same id) reports it as
 * enabled. Consumers such as the shop only see ids, names and labels.
 */
interface PaymentProviderInterface
{
    /**
     * Stable id, stored with every payment and part of the webhook URL, e.g. "mollie".
     */
    public static function id(): string;

    /**
     * Position in the checkout: higher first. Payment on site comes last.
     */
    public static function priority(): int;

    /**
     * Short name for customers and the backend, e.g. "Mollie".
     */
    public function name(): string;

    /**
     * Label of the choice in the checkout (German; consumers translate it).
     */
    public function label(): string;

    /**
     * Whether the money moves through an online provider. Such payments belong to the
     * test or live mode they were created in, their refunds are reserved and
     * committed before the provider is called, and the cron reconciles them. The
     * others are confirmed by hand in the backend.
     */
    public function online(): bool;

    public function createCheckout(PaymentRequest $request): CheckoutResult;

    public function parseWebhook(Request $request): PaymentEvent;

    public function refund(Payment $payment, Money $money, string $operation): RefundResult;

    public function fetchStatus(Payment $payment): PaymentStatus;
}
