<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Controller;

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

    #[Route('/_nw/payment/webhook/{provider}', name: 'nw_payment_webhook', methods: ['POST'], defaults: ['_scope' => 'frontend', '_token_check' => false], options: ['stateless' => true])]
    public function __invoke(Request $request, string $provider): Response
    {
        try {
            $event = $this->payments->provider($provider)->parseWebhook($request);
            $this->payments->apply($provider, $event);
        } catch (UnsupportedEventException) {
            return new Response('', 204);
        } catch (\InvalidArgumentException|SignatureVerificationException|\UnexpectedValueException) {
            return new Response('Invalid payment webhook.', 400);
        }

        return new Response('', 204);
    }
}
