<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\DependencyInjection;

use Nordwerk\PaymentBundle\Domain\PayableResolverInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class NordwerkPaymentExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(PayableResolverInterface::class)->addTag('nordwerk.payment.payable_resolver');
        (new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config')))->load('services.yaml');
    }
}
