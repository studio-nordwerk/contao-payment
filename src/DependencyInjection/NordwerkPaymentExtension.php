<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\DependencyInjection;

use Nordwerk\PaymentBundle\Domain\PayableResolverInterface;
use Nordwerk\PaymentBundle\Domain\PaymentProviderInterface;
use Nordwerk\PaymentBundle\Settings\ProviderSettingsInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class NordwerkPaymentExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(PayableResolverInterface::class)->addTag('nordwerk.payment.payable_resolver');
        // A provider is a PaymentProviderInterface plus a ProviderSettingsInterface with
        // the same id; both are found by autoconfiguration, also in other bundles.
        $container->registerForAutoconfiguration(PaymentProviderInterface::class)->addTag('nordwerk.payment.provider');
        $container->registerForAutoconfiguration(ProviderSettingsInterface::class)->addTag('nordwerk.payment.provider_settings');
        (new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config')))->load('services.yaml');
    }
}
