<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AnalyticsOverviewDto;
use App\Security\PermissionChecker;
use App\Service\VideoAnalyticsService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AnalyticsController extends AbstractController
{
    public function __construct(
        private readonly VideoAnalyticsService $analyticsService,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('/api/analytics/overview', name: 'api_analytics_overview', methods: ['GET'])]
    #[OA\Get(
        path: '/api/analytics/overview',
        operationId: 'getAnalyticsOverview',
        summary: 'Get library-wide watch analytics — totals plus a per-video summary, sorted by views descending',
        tags: ['Analytics'],
        responses: [
            new OA\Response(response: 200, description: 'Overview computed', content: new OA\JsonContent(ref: new Model(type: AnalyticsOverviewDto::class))),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function overview(): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->analyticsService->buildOverview());
    }
}
