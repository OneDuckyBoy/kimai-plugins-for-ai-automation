<?php

declare(strict_types=1);

namespace KimaiPlugin\OrbisRolesBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class OrbisRolesExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // NOTE: Kimai does not register the `doctrine` extension into the container at the point a
        // plugin extension runs, so loadFromExtension('doctrine', ...) throws "extension not
        // registered". The roles are read with Doctrine\DBAL\Connection (as the original
        // ProjectRolesApiBundle did) and the tables are created by SQL in Migrations/Version…- see
        // docs in this bundle. No ORM entity mapping is registered here.
    }
}