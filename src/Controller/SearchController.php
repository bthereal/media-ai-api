<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\PermissionChecker;
use App\Service\VideoSearchService;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly VideoSearchService $videoSearchService,
        #[Autowire(service: 'ai.agent.video_search')]
        private readonly AgentInterface $searchAgent,
    ) {
    }

    #[Route('/api/content/search', name: 'api_content_search', methods: ['POST'])]
    public function search(Request $request): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $query = trim((string) ($body['query'] ?? ''));

        if ('' === $query) {
            return $this->json(['ok' => false, 'error' => 'query must be a non-empty string.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (strlen($query) > 500) {
            return $this->json(['ok' => false, 'error' => 'query must be 500 characters or fewer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $videos = [];
        foreach ($this->videoSearchService->search($query) as $hit) {
            $content = $hit['content'];
            $videos[] = [
                'id' => (string) $content->getId(),
                'title' => $content->getTitle() ?? $content->getFilename(),
                'summary' => '' !== $hit['text'] ? $hit['text'] : null,
            ];
        }

        $result = $this->searchAgent->call(new MessageBag(Message::ofUser($query)));

        if (!$result instanceof TextResult) {
            return $this->json(['ok' => false, 'error' => 'Search failed. Please try again.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'ok' => true,
            'answer' => $result->getContent(),
            'videos' => $videos,
        ]);
    }
}
