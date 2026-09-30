<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Nordwerk\PaymentBundle\Persistence\PaymentService;

#[AsCronJob('minutely')]
final readonly class ReconcilePayments
{
    public function __construct(private PaymentService $payments)
    {
    }

    public function __invoke(): void
    {
        $this->payments->reconcile();
    }
}
