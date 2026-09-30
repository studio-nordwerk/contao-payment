<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NordwerkPaymentBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
