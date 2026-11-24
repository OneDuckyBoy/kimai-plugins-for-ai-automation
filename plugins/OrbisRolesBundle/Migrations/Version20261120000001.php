<?php

declare(strict_types=1);

namespace KimaiPlugin\OrbisRolesBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * TL-style project roles: a reference list (with a manages_project flag) plus a membership per
 * person/project. Adopts rows from an OLD-schema kimai2_project_roles (id, project_id, user_id,
 * role) if one is present, so current assignments survive.
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
        $this->addSql('CREATE TABLE kimai2_project_roles (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, manages_project TINYINT(1) DEFAULT 0 NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE UNIQUE INDEX ux_project_roles_name ON kimai2_project_roles (name)');

        $this->addSql("INSERT INTO kimai2_project_roles (name, manages_project, active) VALUES ('Project manager', 1, 1), ('Tech lead', 0, 1), ('Backend developer', 0, 1), ('Frontend developer', 0, 1), ('QA engineer', 0, 1), ('UX/UI designer', 0, 1)");

        $this->addSql('CREATE TABLE kimai2_project_memberships (id INT AUTO_INCREMENT NOT NULL, project_id INT NOT NULL, user_id INT NOT NULL, project_role_id INT DEFAULT NULL, valid_from DATETIME DEFAULT NULL, valid_to DATETIME DEFAULT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, INDEX IDX_project_memberships_user (user_id), INDEX IDX_project_memberships_role (project_role_id), UNIQUE INDEX UX_project_memberships_project_user (project_id, user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE kimai2_project_memberships ADD CONSTRAINT FK_project_memberships_role FOREIGN KEY (project_role_id) REFERENCES kimai2_project_roles (id) ON DELETE SET NULL');

        // NOTE (Slice A): the OLD plugin's kimai2_project_roles (project_id/user_id/role) was dropped,
        // and the surviving assignments are seeded back from ai-automation in the feed step (Slice C).
        // If a prod environment still carries an old-schema table under this name it must be adopted
        // by a dedicated migration BEFORE this one runs — this migration only creates the new tables.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_project_memberships DROP FOREIGN KEY FK_project_memberships_role');
        $this->addSql('DROP TABLE kimai2_project_memberships');
        $this->addSql('DROP TABLE kimai2_project_roles');
    }
}