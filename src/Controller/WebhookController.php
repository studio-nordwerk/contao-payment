<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Controller;

use Nordwerk\PaymentBundle\Domain\UnsignedWebhookInterface;
use Nordwerk\PaymentBundle\Persistence\PaymentService;
use Nordwerk\PaymentBundle\Provider\UnsupportedEventException;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class WebhookController
{
    public function __construct(private PaymentService $payments)
    {
    }

    /**
     * Answers 200 to everything that is not invalid: providers retry on anything
     * else, and some (Mollie) expect exactly 200.
     */
    #[Route('/_nw/payment/webhook/{provider}', name: 'nw_payment_webhook', methods: ['POST'], defaults: ['_scope' => 'frontend', '_token_check' => false], options: ['stateless' => true])]
    public function __invoke(Request $request, string $provider): Response
    {
        try {
            $gateway = $this->payments->provider($provider);
            // An unsigned webhook only names a payment: first look whether it is ours, so
            // strangers cannot make us call the provider's API.
            if ($gateway instanceof UnsignedWebhookInterface && null !== ($reference = $gateway->webhookReference($request)) && !$this->payments->hasReference($provider, $reference)) {
                return new Response('', 200);
            }
            $this->payments->apply($provider, $gateway->parseWebhook($request));
        } catch (UnsupportedEventException) {
            return new Response('', 200);
        } catch (\InvalidArgumentException|SignatureVerificationException|\UnexpectedValueException) {
            return new Response('Invalid payment webhook.', 400);
        }

        return new Response('', 200);
    }
}
