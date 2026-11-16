<?php

namespace KimaiPlugin\WorkingTimeApiBundle\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\WorkingTime\WorkingTimeService;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\View\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Expected vs recorded working time, the way ai-automation's month view needs it.
 * The calculation itself is Kimai core (WorkingTimeService + contract preferences);
 * this plugin only exposes it over the REST API, which the core does not.
 */
#[IsGranted('API')]
final class WorkingTimeApiController extends AbstractFOSRestController
{
    public function __construct(
        private readonly WorkingTimeService $workingTimeService,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Expected vs recorded minutes for one user and one month.
     *
     * `user` is the target's email or username; without it the caller's own month
     * is returned. Reading someone else's month needs `view_other_timesheet`.
     */
    #[Route(methods: ['GET'], path: '/working-time/month', name: 'working_time_api_month')]
    public function monthAction(Request $request): Response
    {
        $caller = $this->getUser();
        if (!$caller instanceof User) {
            throw new AccessDeniedException('Need a user for API access');
        }

        $monthValue = (string) $request->query->get('month', '');
        $monthDate = \DateTimeImmutable::createFromFormat('Y-m', $monthValue);
        if ($monthDate === false) {
            return $this->json(['message' => 'Invalid month, expected YYYY-MM'], Response::HTTP_BAD_REQUEST);
        }
        $monthDate = $monthDate->setDate((int) $monthDate->format('Y'), (int) $monthDate->format('m'), 1);

        $target = $caller;
        $userParam = (string) $request->query->get('user', '');
        if ($userParam !== '') {
            if (!$this->isGranted('view_other_timesheet')) {
                throw new AccessDeniedException('Reading another user\'s working time needs view_other_timesheet');
            }
            try {
                $found = $this->userRepository->loadUserByIdentifier($userParam);
                if (!$found instanceof User) {
                    throw new UserNotFoundException();
                }
                $target = $found;
            } catch (UserNotFoundException) {
                return $this->json(['message' => 'Unknown user'], Response::HTTP_NOT_FOUND);
            }
        }

        $until = new \DateTimeImmutable('now');
        $month = $this->workingTimeService->getMonth($target, $monthDate, $until);

        $days = [];
        foreach ($month->getDays() as $day) {
            $workingTime = $day->getWorkingTime();
            $days[] = [
                'date' => $day->getDay()->format('Y-m-d'),
                // Kimai keeps both in seconds (the contract's hours too, as its DurationType form saves them).
                'expectedMinutes' => (int) round(($workingTime?->getExpectedTime() ?? 0) / 60),
                'recordedMinutes' => (int) round(($workingTime?->getActualTime() ?? 0) / 60),
            ];
        }

        return $this->json([
            'month' => $monthDate->format('Y-m'),
            'expectedMinutes' => (int) round($month->getExpectedTime($until) / 60),
            'recordedMinutes' => (int) round($month->getActualTime() / 60),
            'days' => $days,
        ], Response::HTTP_OK);
    }
}
