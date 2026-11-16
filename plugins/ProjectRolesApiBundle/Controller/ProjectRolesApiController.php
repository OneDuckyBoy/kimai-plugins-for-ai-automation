<?php

namespace KimaiPlugin\ProjectRolesApiBundle\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Per-person roles on a project (PM, Backend, Frontend, QA, ...). Kimai core has no
 * per-project role of its own — teamlead is a team's technical lead, not a role you can
 * put on an arbitrary project member. This plugin stores one role per person per project in
 * its own table and exposes read/write over the REST API, which is what the timesheet
 * generator and the chat agent consume. Writing someone's role needs the same admin surface
 * as the API token carries; the bot names the target person to act for.
 */
#[IsGranted('API')]
final class ProjectRolesApiController extends AbstractFOSRestController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /** The roles on one project, each joined to the person's display name and email. */
    public function rolesAction(Request $request): Response
    {
        $projectId = (int) $request->query->get('project', 0);
        if ($projectId <= 0 || !$this->projectExists($projectId)) {
            return $this->json(['message' => 'Unknown project'], Response::HTTP_NOT_FOUND);
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.user_id, r.role, u.username, u.email, u.alias AS display_name
             FROM kimai2_project_roles r
             JOIN kimai2_users u ON u.id = r.user_id
             WHERE r.project_id = ?',
            [$projectId],
        );

        $roles = [];
        foreach ($rows as $row) {
            $roles[] = [
                'userId' => (int) $row['user_id'],
                'username' => $row['username'],
                'email' => $row['email'],
                'displayName' => $row['display_name'],
                'role' => $row['role'],
            ];
        }

        return $this->json(['project' => $projectId, 'roles' => $roles], Response::HTTP_OK);
    }

    /** Stores or replaces one person's role on a project. Body: {project, user, role}. */
    public function setAction(Request $request): Response
    {
        $caller = $this->getUser();
        if (!$caller instanceof User) {
            throw new AccessDeniedException('Need a user for API access');
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Body must be JSON'], Response::HTTP_BAD_REQUEST);
        }

        $projectId = (int) ($payload['project'] ?? 0);
        $userParam = trim((string) ($payload['user'] ?? ''));
        $role = trim((string) ($payload['role'] ?? ''));

        $errors = [];
        if ($projectId <= 0) {
            $errors[] = 'project is required';
        }
        if ($userParam === '') {
            $errors[] = 'user (id, username or email) is required';
        }
        if ($role === '') {
            $errors[] = 'role is required';
        }
        if ($errors) {
            return $this->json(['message' => implode('; ', $errors), 'errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->findUser($userParam);
        if ($user === null) {
            return $this->json(['message' => 'Unknown user'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->projectExists($projectId)) {
            return $this->json(['message' => 'Unknown project'], Response::HTTP_NOT_FOUND);
        }
        if (mb_strlen($role) > 60) {
            return $this->json(['message' => 'role is too long (max 60)' ], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($role, [new Assert\NotBlank(), new Assert\Length(['max' => 60])]);
        if (count($violations) > 0) {
            return $this->json(['message' => 'invalid role'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->connection->fetchOne(
            'SELECT id FROM kimai2_project_roles WHERE project_id = ? AND user_id = ?',
            [$projectId, (int) $user['id']],
        );

        if ($existing) {
            $this->connection->update(
                'kimai2_project_roles',
                ['role' => $role],
                ['id' => $existing],
            );
        } else {
            $this->connection->insert('kimai2_project_roles', [
                'project_id' => $projectId,
                'user_id' => (int) $user['id'],
                'role' => $role,
            ]);
        }

        return $this->json([
            'project' => $projectId,
            'userId' => (int) $user['id'],
            'email' => $user['email'],
            'displayName' => $user['alias'],
            'role' => $role,
        ], Response::HTTP_OK);
    }

    private function projectExists(int $projectId): bool
    {
        return (bool) $this->connection->fetchOne('SELECT COUNT(*) FROM kimai2_projects WHERE id = ?', [$projectId]);
    }

    /** Resolves a "user" by numeric id, username or email. */
    private function findUser(string $param): ?array
    {
        if (ctype_digit($param)) {
            $row = $this->connection->fetchAssociative('SELECT id, username, email, alias FROM kimai2_users WHERE id = ?', [(int) $param]);
            if ($row) {
                return $row;
            }
        }
        $row = $this->connection->fetchAssociative(
            'SELECT id, username, email, alias FROM kimai2_users WHERE username = ? OR email = ? LIMIT 1',
            [$param, $param],
        );
        return $row ?: null;
    }
}