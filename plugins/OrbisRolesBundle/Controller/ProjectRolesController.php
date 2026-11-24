<?php

declare(strict_types=1);

namespace KimaiPlugin\OrbisRolesBundle\Controller;

use App\Entity\User;
use KimaiPlugin\OrbisRolesBundle\Service\ProjectRoleScopeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read route for ai-automation: the people and their roles on one project (the Excel Role column
 * and the PM grouping). Scope-gated by ProjectRoleScopeService. Only an administrator (who holds
 * `view_project_roles`) or the read-only sync account (`view_project_roles_api`) may call it; a
 * plain employee's token is refused. A Bearer token is required (the /api firewall).
 */
#[IsGranted(new Expression("is_granted('view_project_roles') or is_granted('view_project_roles_api')"))]
final class ProjectRolesController extends AbstractController
{
    public function __construct(
        private readonly ProjectRoleScopeService $scope,
    ) {
    }

    public function rolesAction(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Need a user for API access');
        }

        $projectId = (int) $request->query->get('project', 0);
        if ($projectId <= 0) {
            return $this->json(['message' => 'A positive project id is required (?project=N).'], Response::HTTP_BAD_REQUEST);
        }

        $this->scope->requireReadProject($user, $projectId, new \DateTimeImmutable('now'));

        return $this->json([
            'project' => $projectId,
            'roles' => $this->scope->rolesForProject($projectId),
        ]);
    }
}