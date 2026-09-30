<?php

declare(strict_types=1);

namespace Nordwerk\PaymentBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Nordwerk\PaymentBundle\NordwerkPaymentBundle;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

final class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [BundleConfig::create(NordwerkPaymentBundle::class)->setLoadAfter([ContaoCoreBundle::class])];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): RouteCollection
    {
        $path = __DIR__.'/../../config/routes.yaml';
        $loader = $resolver->resolve($path);
        if (false === $loader) {
            throw new \RuntimeException('Cannot load mini shop routes.');
        }

        return $loader->load($path);
    }
}
