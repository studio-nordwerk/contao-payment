<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Controller;

use Doctrine\DBAL\Connection;
use Nordwerk\PaymentBundle\Domain\PaymentStatus;
use Nordwerk\PaymentBundle\Persistence\PaymentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class ReturnController
{
    public function __construct(
        private Connection $connection,
        private PaymentRepository $repository,
        private Environment $twig,
    ) {
    }

    #[Route('/_nw/payment/return/{token}', name: 'nw_payment_return', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'], options: ['stateless' => true])]
    public function __invoke(string $token): Response
    {
        $id = $this->connection->fetchOne('SELECT id FROM tl_nw_payment WHERE return_token = ?', [$token]);
        if (false === $id) {
            throw new NotFoundHttpException();
        }
        $payment = $this->repository->find((int) $id);
        $pending = \in_array($payment->status, [PaymentStatus::Open, PaymentStatus::Pending], true);
        $message = match ($payment->status) {
            PaymentStatus::Paid => 'Zahlung bestätigt',
            PaymentStatus::Failed => 'Zahlung fehlgeschlagen',
            PaymentStatus::Expired => 'Zahlung abgelaufen',
            PaymentStatus::Refunded => 'Zahlung erstattet',
            PaymentStatus::PartiallyRefunded => 'Zahlung teilweise erstattet',
            default => 'Zahlung wird geprüft',
        };

        return new Response($this->twig->render('@NordwerkPayment/return.html.twig', ['payment' => $payment, 'pending' => $pending, 'message' => $message]), 200, ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex']);
    }
}
