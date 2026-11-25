<?php

declare(strict_types=1);

namespace KimaiPlugin\OrbisRolesBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * TL-style project roles: a reference list (with a manages_project flag) plus a membership per
 * person/project.
 *
 * Before the installer existed these tables were created by hand on some Kimais. A table that is
 * already there in this layout is adopted as it is (rows kept, vocabulary not re-seeded), so the
 * installer records this version instead of failing on CREATE TABLE. A kimai2_project_roles in the
 * OLD per-person layout (project_id, user_id, role) stops the install: its assignments have to be
 * moved out by hand first, since this migration only creates the new tables.
 *
 * @version 0.1
 */
final class Version20261120000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create project roles + memberships (TL-style), seed the role vocabulary';
    }

    public function up(Schema $schema): void
    {
        $hasRoles = $schema->hasTable('kimai2_project_roles');
        $hasMemberships = $schema->hasTable('kimai2_project_memberships');

        if ($hasRoles) {
            $this->abortIf(
                !$schema->getTable('kimai2_project_roles')->hasColumn('manages_project'),
                'kimai2_project_roles has the old per-person layout (project_id, user_id, role). Move its assignments out and drop the table, then run the installer again.'
            );
        } else {
            $this->addSql('CREATE TABLE kimai2_project_roles (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, manages_project TINYINT(1) DEFAULT 0 NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
            $this->addSql('CREATE UNIQUE INDEX ux_project_roles_name ON kimai2_project_roles (name)');

            $this->addSql("INSERT INTO kimai2_project_roles (name, manages_project, active) VALUES ('Project manager', 1, 1), ('Tech lead', 0, 1), ('Backend developer', 0, 1), ('Frontend developer', 0, 1), ('QA engineer', 0, 1), ('UX/UI designer', 0, 1)");
        }

        if (!$hasMemberships) {
            $this->addSql('CREATE TABLE kimai2_project_memberships (id INT AUTO_INCREMENT NOT NULL, project_id INT NOT NULL, user_id INT NOT NULL, project_role_id INT DEFAULT NULL, valid_from DATETIME DEFAULT NULL, valid_to DATETIME DEFAULT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, INDEX IDX_project_memberships_user (user_id), INDEX IDX_project_memberships_role (project_role_id), UNIQUE INDEX UX_project_memberships_project_user (project_id, user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
            $this->addSql('ALTER TABLE kimai2_project_memberships ADD CONSTRAINT FK_project_memberships_role FOREIGN KEY (project_role_id) REFERENCES kimai2_project_roles (id) ON DELETE SET NULL');
        }

        if ($hasRoles && $hasMemberships) {
            $this->preventEmptyMigrationWarning();
        }
    }

    public function down(Schema $schema): void
    {
        // Through the schema: Kimai's addSql() refuses DROP TABLE. Dropping the memberships table
        // takes its foreign key with it.
        $schema->dropTable('kimai2_project_memberships');
        $schema->dropTable('kimai2_project_roles');
    }
}
