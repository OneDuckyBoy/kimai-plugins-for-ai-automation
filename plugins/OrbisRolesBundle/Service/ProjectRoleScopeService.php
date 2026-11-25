<?php

declare(strict_types=1);

namespace KimaiPlugin\OrbisRolesBundle\Service;

use App\Entity\User;
use App\Security\RolePermissionManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * TL-style scope: derives what a caller may see from their role and the memberships, never trusts
 * a menu. An administrator sees every project; a project manager only the projects where their
 * ACTIVE membership points at a role that manages the project and is valid today. A caller holding
 * `view_project_roles_api` (a machine account, not an administrator) is treated like an admin for
 * READING roles — it sees every project but cannot touch the edit screens, which need their own
 * `edit_project_roles` permission.
 *
 * Reads the roles tables directly through DBAL (the ORM entity mapping is not registered), so the
 * tables must exist — Migrations/Version….php creates them (or run them via SQL against Kimai).
 */
final class ProjectRoleScopeService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RolePermissionManager $permissions,
    ) {
    }

    public function isAdmin(User $user): bool
    {
        $roles = $user->getRoles();
        return \in_array('ROLE_SUPER_ADMIN', $roles, true) || \in_array('ROLE_ADMIN', $roles, true);
    }

    /** Whether the caller may read any project's roles: an admin, or the sync's read-only account. */
    public function canReadEveryProject(User $user): bool
    {
        return $this->isAdmin($user) || $this->permissions->hasRolePermission($user, 'view_project_roles_api');
    }

    /** The project ids the user manages today (admins/read-all: null = every project). */
    public function managedProjectIds(User $user, \DateTimeImmutable $now): ?array
    {
        if ($this->canReadEveryProject($user)) {
            return null; // admins and the sync account see every project
        }

        $rows = $this->connection->fetchFirstColumn(
            'SELECT m.project_id
             FROM kimai2_project_memberships m
             INNER JOIN kimai2_project_roles r ON r.id = m.project_role_id
             WHERE m.user_id = ?
               AND m.active = 1
               AND r.manages_project = 1
               AND (m.valid_from IS NULL OR m.valid_from <= ?)
               AND (m.valid_to IS NULL OR m.valid_to >= ?)',
            [(int) $user->getId(), $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')],
        );

        return array_map('intval', $rows);
    }

    public function canReadProject(User $user, int $projectId, \DateTimeImmutable $now): bool
    {
        $managed = $this->managedProjectIds($user, $now);
        if ($managed === null) {
            return true;
        }

        return \in_array($projectId, $managed, true);
    }

    public function requireReadProject(User $user, int $projectId, \DateTimeImmutable $now): void
    {
        if (!$this->canReadProject($user, $projectId, $now)) {
            throw new AccessDeniedException('You cannot read that project\'s roles.');
        }
    }

    /**
     * The people + roles on one project today, for the Excel Role column and the PM grouping: only
     * ACTIVE memberships valid on `$now`, the same rule managedProjectIds() applies. `managesProject`
     * is the role's flag, so a caller finds the PMs without knowing the role names.
     */
    public function rolesForProject(int $projectId, \DateTimeImmutable $now): array
    {
        $at = $now->format('Y-m-d H:i:s');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id AS userId, u.username AS username, u.email AS email,
                    u.alias AS displayName, r.name AS role, r.manages_project AS managesProject
             FROM kimai2_project_memberships m
             INNER JOIN kimai2_project_roles r ON r.id = m.project_role_id
             INNER JOIN kimai2_users u ON u.id = m.user_id
             WHERE m.project_id = ?
               AND m.active = 1
               AND (m.valid_from IS NULL OR m.valid_from <= ?)
               AND (m.valid_to IS NULL OR m.valid_to >= ?)
             ORDER BY u.username ASC',
            [$projectId, $at, $at],
        );

        return array_map(
            static fn (array $row): array => [
                'userId' => (int) $row['userId'],
                'username' => (string) $row['username'],
                'email' => $row['email'],
                'displayName' => $row['displayName'],
                'role' => (string) $row['role'],
                'managesProject' => (bool) $row['managesProject'],
            ],
            $rows,
        );
    }
}