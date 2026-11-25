<?php

declare(strict_types=1);

namespace KimaiPlugin\OrbisRolesBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * The role's badge colour, which the admin screens read and write. Same type as Kimai's own
 * colour columns (#rrggbb or empty). A column added by hand before the installer existed is kept.
 *
 * @version 0.1
 */
final class Version20261125000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a colour to project roles';
    }

    public function up(Schema $schema): void
    {
        if ($schema->getTable('kimai2_project_roles')->hasColumn('color')) {
            $this->preventEmptyMigrationWarning();

            return;
        }
        $this->addSql('ALTER TABLE kimai2_project_roles ADD color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_project_roles DROP color');
    }
}
