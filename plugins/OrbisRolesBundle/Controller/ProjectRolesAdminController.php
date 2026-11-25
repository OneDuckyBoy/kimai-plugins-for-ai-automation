<?php

declare(strict_types=1);

namespace KimaiPlugin\OrbisRolesBundle\Controller;

use Doctrine\DBAL\Connection;
use App\Utils\PageSetup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Kimai admin screens for the TL-style project roles: manage the role vocabulary and assign a
 * role to a user on a project. Write actions flip `manages_project`/delete roles and upsert
 * memberships — reading stays DBAL (no ORM entity mapping registered).
 *
 * Admin only for now; the `edit_project_roles` / `manage_project_roles` permissions are registered
 * for anyone Kimai grants them to.
 */
final class ProjectRolesAdminController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[IsGranted('view_project_roles')]
    public function listRoles(Request $request): Response
    {
        $roles = $this->connection->fetchAllAssociative(
            'SELECT id, name, color, manages_project, active FROM kimai2_project_roles ORDER BY name ASC'
        );

        $form = $this->createFormBuilder()
            ->add('name', TextType::class, ['label' => 'Role name'])
            ->add('color', \App\Form\Type\ColorChoiceType::class, ['label' => 'Color'])
            ->add('customColor', \App\Form\Type\ColorPickerType::class, ['label' => 'Custom color'])
            ->add('managesProject', CheckboxType::class, ['label' => 'Manages project', 'required' => false])
            ->add('active', CheckboxType::class, ['label' => 'Active', 'required' => false, 'data' => true])
            ->add('create', SubmitType::class, ['label' => 'Add role'])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $custom = \trim((string) ($data['customColor'] ?? ''));
            $color = $custom !== '' ? $custom : (isset($data['color']) ? (string) $data['color'] : '');
            try {
                $this->connection->insert('kimai2_project_roles', [
                    'name' => (string) $data['name'],
                    'color' => $color !== '' ? $color : null,
                    'manages_project' => (int) ($data['managesProject'] ?? false),
                    'active' => (int) ($data['active'] ?? true),
                ]);
                $this->addFlash('success', 'Role added.');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Could not add role (duplicate name?).');
            }

            return $this->redirectToRoute('orbis_roles_admin_list');
        }

        $page = new PageSetup('Project roles');
        $page->setActionName('project_roles');
        $page->setHelp('project-roles.html');

        return $this->render('@OrbisRoles/roles/list.html.twig', [
            'page_setup' => $page,
            'roles' => $roles,
            'form' => $form->createView(),
        ]);
    }

    #[IsGranted('edit_project_roles')]
    public function toggleRole(int $id): Response
    {
        $this->connection->executeStatement(
            'UPDATE kimai2_project_roles SET manages_project = (1 - manages_project) WHERE id = ?',
            [$id]
        );
        $this->addFlash('success', 'Role updated.');

        return $this->redirectToRoute('orbis_roles_admin_list');
    }

    #[IsGranted('edit_project_roles')]
    public function deleteRole(int $id): Response
    {
        // Memberships referencing the role keep the FK 'ON DELETE SET NULL' (role id nulled).
        $this->connection->delete('kimai2_project_roles', ['id' => $id]);
        $this->addFlash('success', 'Role deleted.');

        return $this->redirectToRoute('orbis_roles_admin_list');
    }

    #[IsGranted('view_project_roles')]
    public function members(Request $request): Response
    {
        $projects = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.name, c.name AS customer
             FROM kimai2_projects p LEFT JOIN kimai2_customers c ON c.id = p.customer_id
             ORDER BY p.name ASC'
        );
        $users = $this->connection->fetchAllAssociative('SELECT id, username, email FROM kimai2_users ORDER BY username ASC');
        $roles = $this->connection->fetchAllAssociative('SELECT id, name, color FROM kimai2_project_roles ORDER BY name ASC');
        $roleColors = $this->roleColorByName($roles);

        // The open project lives in the URL only (?project=N), so the assign form, a reload and the
        // links all act on the project shown. Unknown or missing → the top project, so no click on
        // "Open" is needed.
        $projectIds = \array_map(fn($p) => (int) $p['id'], $projects);
        $projectId = (int) $request->query->get('project', 0);
        if (!\in_array($projectId, $projectIds, true)) {
            $projectId = $projectIds[0] ?? 0;
        }

        // Each form has its own name: with Symfony's default ("form") for both, submitting one also
        // counts as submitting the other.
        $projectForm = $this->container->get('form.factory')
            ->createNamedBuilder('project_pick', FormType::class, ['project' => $projectId])
            ->add('project', ChoiceType::class, [
                'label' => 'Project',
                'choices' => $this->toChoices($projects, 'name'),
            ])
            ->add('open', SubmitType::class, ['label' => 'Open'])
            ->getForm();
        $projectForm->handleRequest($request);
        if ($projectForm->isSubmitted() && $projectForm->isValid()) {
            return $this->redirectToRoute('orbis_roles_members', ['project' => (int) $projectForm->getData()['project']]);
        }

        $members = [];
        if ($projectId > 0) {
            $members = $this->connection->fetchAllAssociative(
                'SELECT m.user_id, m.project_role_id, m.active, m.valid_from, m.valid_to,
                        u.username, u.email, r.name AS role, r.color AS role_color
                 FROM kimai2_project_memberships m
                 LEFT JOIN kimai2_users u ON u.id = m.user_id
                 LEFT JOIN kimai2_project_roles r ON r.id = m.project_role_id
                 WHERE m.project_id = ?
                 ORDER BY u.username ASC',
                [$projectId]
            );
            foreach ($members as $i => $m) {
                $members[$i]['role_text_color'] = $this->contrastText((string) ($m['role_color'] ?? ''));
            }
        }

        // The assign form only offers people NOT already on the project (avoid duplicates).
        $memberIds = $projectId > 0
            ? \array_map('intval', $this->connection->fetchFirstColumn(
                'SELECT user_id FROM kimai2_project_memberships WHERE project_id = ?',
                [$projectId]
            ))
            : [];
        $availableUsers = \array_filter(
            $users,
            fn($u) => !\in_array((int) $u['id'], $memberIds, true)
        );

        // Optional inline edit: ?edit_user=ID pre-fills the assign form with that membership.
        $editUserId = (int) $request->query->get('edit_user', 0);
        $editMembership = null;
        if ($projectId > 0 && $editUserId > 0) {
            $editMembership = $this->connection->fetchAssociative(
                'SELECT user_id, project_role_id, active, valid_from, valid_to
                 FROM kimai2_project_memberships WHERE project_id = ? AND user_id = ?',
                [$projectId, $editUserId]
            );
        }

        $setDefaults = $editMembership
            ? [
                'userId' => (int) $editMembership['user_id'],
                'roleId' => $editMembership['project_role_id'] !== null ? (int) $editMembership['project_role_id'] : '',
                'active' => (bool) $editMembership['active'],
            ]
            : ['active' => true];
        $setForm = $this->container->get('form.factory')
            ->createNamedBuilder('assignment', FormType::class, $setDefaults, [
                'action' => $this->generateUrl('orbis_roles_members', \array_filter([
                    'project' => $projectId,
                    'edit_user' => $editMembership ? $editUserId : null,
                ])),
            ])
            ->add('userId', ChoiceType::class, ['label' => 'Person', 'choices' => $this->toChoices($availableUsers, 'username')])
            ->add('roleId', ChoiceType::class, [
                'label' => 'Role',
                'choices' => $this->toChoices($roles, 'name'),
                'choice_attr' => fn($value, $label) => ['data-color' => $roleColors[$label] ?? ''],
                'attr' => ['data-renderer' => 'color'],
            ])
            ->add('active', CheckboxType::class, ['label' => 'Active', 'required' => false])
            ->add('save', SubmitType::class, ['label' => $editMembership ? 'Save' : 'Assign'])
            ->getForm();
        $setForm->handleRequest($request);

        if ($setForm->isSubmitted() && $setForm->isValid() && $projectId > 0) {
            $data = $setForm->getData();
            $this->connection->executeStatement(
                'INSERT INTO kimai2_project_memberships (project_id, user_id, project_role_id, active, valid_from, valid_to)
                 VALUES (?, ?, ?, ?, NULL, NULL)
                 ON DUPLICATE KEY UPDATE project_role_id = VALUES(project_role_id), active = VALUES(active)',
                [
                    $projectId,
                    (int) $data['userId'],
                    $data['roleId'] !== '' ? (int) $data['roleId'] : null,
                    (int) ($data['active'] ?? false),
                ]
            );
            $this->addFlash('success', 'Assignment saved.');

            return $this->redirectToRoute('orbis_roles_members', ['project' => $projectId]);
        }

        $page = new PageSetup('Project role assignments');
        $page->setActionName('project_roles');

        return $this->render('@OrbisRoles/roles/members.html.twig', [
            'page_setup' => $page,
            'projectForm' => $projectForm->createView(),
            'setForm' => $setForm->createView(),
            'projectId' => $projectId,
            'editUserId' => $editMembership ? $editUserId : 0,
            'members' => $members,
        ]);
    }

    #[IsGranted('edit_project_roles')]
    public function editMembership(Request $request, int $project, int $user): Response
    {
        $membership = $this->connection->fetchAssociative(
            'SELECT m.user_id, m.project_role_id, m.active, u.username
             FROM kimai2_project_memberships m
             LEFT JOIN kimai2_users u ON u.id = m.user_id
             WHERE m.project_id = ? AND m.user_id = ?',
            [$project, $user]
        );
        if (!$membership) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Assignment not found.');
        }
        $roles = $this->connection->fetchAllAssociative('SELECT id, name, color FROM kimai2_project_roles ORDER BY name ASC');
        $roleColors = $this->roleColorByName($roles);

        $form = $this->createFormBuilder([
            'roleId' => $membership['project_role_id'] !== null ? (int) $membership['project_role_id'] : '',
            'active' => (bool) $membership['active'],
        ])
            ->add('roleId', ChoiceType::class, [
                'label' => 'Role',
                'choices' => $this->toChoices($roles, 'name'),
                'choice_attr' => fn($value, $label) => ['data-color' => $roleColors[$label] ?? ''],
                'attr' => ['data-renderer' => 'color'],
            ])
            ->add('active', CheckboxType::class, ['label' => 'Active', 'required' => false])
            ->add('save', SubmitType::class, ['label' => 'Save'])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $this->connection->executeStatement(
                'UPDATE kimai2_project_memberships
                 SET project_role_id = ?, active = ?, valid_from = NULL, valid_to = NULL
                 WHERE project_id = ? AND user_id = ?',
                [$data['roleId'] !== '' ? (int) $data['roleId'] : null, (int) ($data['active'] ?? false), $project, $user]
            );
            $this->addFlash('success', 'Assignment saved.');

            return $this->redirectToRoute('orbis_roles_members', ['project' => $project]);
        }

        return $this->render('@OrbisRoles/roles/members-edit.html.twig', [
            'username' => $membership['username'] ?? ('user #' . $user),
            'form' => $form->createView(),
        ]);
    }

    #[IsGranted('edit_project_roles')]
    public function deleteMembership(Request $request): Response
    {
        $projectId = (int) $request->query->get('project', 0);
        $userId = (int) $request->query->get('user', 0);
        if ($projectId <= 0 || $userId <= 0) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('project and user are required.');
        }

        $this->connection->delete('kimai2_project_memberships', ['project_id' => $projectId, 'user_id' => $userId]);
        $this->addFlash('success', 'Assignment removed.');

        return $this->redirectToRoute('orbis_roles_members', ['project' => $projectId]);
    }

    /** '#000000' on a light colour, '#ffffff' on a dark one, so badge text stays readable. */
    private function contrastText(string $hex): string
    {
        $hex = \ltrim($hex, '#');
        if (\strlen($hex) !== 6) {
            return '#ffffff';
        }
        $r = \hexdec(\substr($hex, 0, 2));
        $g = \hexdec(\substr($hex, 2, 2));
        $b = \hexdec(\substr($hex, 4, 2));
        $luminance = 0.299 * $r + 0.587 * $g + 0.114 * $b;

        return $luminance > 140 ? '#000000' : '#ffffff';
    }

    /** ['name' => 'color'] from the roles result set (for the colored dropdown dots). */
    private function roleColorByName(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['name']] = (string) ($row['color'] ?? '');
        }

        return $out;
    }

    /** ['value' => 'label'] for ChoiceType from a DB result set. */
    private function toChoices(array $rows, string $label): array
    {
        $out = [];
        foreach ($rows as $row) {
            $labelText = $row[$label] ?? '';
            if (isset($row['email']) && $row['email']) {
                $labelText .= ' — ' . $row['email'];
            }
            $out[$labelText] = (int) $row['id'];
        }

        return $out;
    }
}