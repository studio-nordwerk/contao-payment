<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Backend;

use Contao\BackendModule;
use Contao\BackendTemplate;
use Contao\Input;
use Contao\System;
use Doctrine\DBAL\Connection;
use Nordwerk\PaymentBundle\Domain\Money;
use Nordwerk\PaymentBundle\Persistence\PaymentRepository;
use Nordwerk\PaymentBundle\Persistence\PaymentService;

/**
 * @property BackendTemplate $Template
 */
final class PaymentsModule extends BackendModule
{
    protected $strTemplate = 'be_nw_payments';

    protected function compile(): void
    {
        /** @var PaymentRepository $repository */
        $repository = System::getContainer()->get(PaymentRepository::class);
        /** @var PaymentService $service */
        $service = System::getContainer()->get(PaymentService::class);
        /** @var Connection $connection */
        $connection = System::getContainer()->get('database_connection');
        $error = '';
        $notice = '';
        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
            try {
                $id = (int) Input::post('payment');
                if ('confirm' === Input::post('action')) {
                    $service->confirmManual($id);
                    $notice = 'Vorkasse bestätigt.';
                } else {
                    $raw = Input::post('amount');
                    $operation = Input::post('operation');
                    if (!\is_string($raw) || !preg_match('/^[0-9]{1,12}$/D', $raw) || !\is_string($operation)) {
                        throw new \InvalidArgumentException('Betrag in ganzen Cent eingeben.');
                    }
                    $payment = $repository->find($id);
                    $result = $service->refund($id, new Money((int) $raw, $payment->money->currency), $operation);
                    $notice = $result->succeeded ? 'Erstattung bestätigt.' : 'Erstattung beim Anbieter wird geprüft.';
                }
            } catch (\Throwable $exception) {
                $error = $exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'Zahlungsaktion fehlgeschlagen. Bitte erneut prüfen.';
            }
        }
        $this->Template->setData(['payments' => $repository->recent(), 'events' => $connection->fetchAllAssociative('SELECT * FROM tl_nw_payment_event ORDER BY id DESC LIMIT 100'), 'error' => $error, 'notice' => $notice]);
    }
}
