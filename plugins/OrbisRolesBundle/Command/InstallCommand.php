<?php

declare(strict_types=1);

namespace KimaiPlugin\OrbisRolesBundle\Command;

use App\Command\AbstractBundleInstallerCommand;

/**
 * `kimai:bundle:orbisroles:install`: Kimai runs a plugin's migrations only through its installer
 * command, so without this the roles tables are never created. Safe to re-run: done migrations
 * are skipped.
 */
final class InstallCommand extends AbstractBundleInstallerCommand
{
    protected function getBundleCommandNamePart(): string
    {
        return 'orbisroles';
    }

    protected function getMigrationConfigFilename(): ?string
    {
        return __DIR__ . '/../Resources/config/doctrine_migrations.yaml';
    }
}
