<?php

declare(strict_types=1);

namespace KimaiPlugin\OrbisRolesBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Per-project roles (project manager and the rest), TL-style: a reference list of roles with a
 * `managesProject` flag, a membership per person/project pointing at a role, and a scope service
 * that derives what a caller may see (an admin everything, a project manager their own projects).
 * Exposes a read route so ai-automation can fetch roles for the Excel/PM grouping again.
 */
final class OrbisRolesBundle extends Bundle implements PluginInterface
{
    public function build(ContainerBuilder $container): void
    {
        // Merge our permission strings into Kimai's permission registry. Kimai grants a permission
        // through kimai.permissions.roles (role name -> permission list); the node is
        // `useAttributeAsKey` so this APPENDS to a role's existing list (Kimai's own names survive).
        // Adding here both registers the name and grants it to that role — the empty
        // kimai2_roles_permissions DB table is not how 2.55 grants role permissions.
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'roles' => [
                    'ROLE_ADMIN' => [
                        'view_project_roles',
                        'view_project_roles_api',
                        'edit_project_roles',
                        'manage_project_roles',
                    ],
                    'ROLE_SUPER_ADMIN' => [
                        'view_project_roles',
                        'view_project_roles_api',
                        'edit_project_roles',
                        'manage_project_roles',
                    ],
                    // The read-only machine account the sync uses: it may READ every project's roles
                    // (scope treats it like an admin for reads) but must never edit them.
                    'ROLE_ORBIS_SYNC' => [
                        'view_project_roles_api',
                    ],
                ],
                // A role listed here gets its `roles` grants built into a permission => true map.
                // Without ROLE_ORBIS_SYNC in maps, the processor keeps its grants as a bare list and
                // `hasRolePermission` can never see them (the db-driven empty tables are irrelevant).
                'maps' => [
                    'ROLE_ORBIS_SYNC' => [],
                ],
            ],
        ]);
    }
}