<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ContentRepository;
use App\Security\PermissionChecker;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Store\RetrieverInterface;
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
        private readonly ContentRepository $contentRepository,
        #[Autowire(service: 'ai.agent.video_search')]
        private readonly AgentInterface $searchAgent,
        #[Autowire(service: 'ai.retriever.video_transcript_embeds')]
        private readonly RetrieverInterface $retriever,
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

        $matched = iterator_to_array($this->retriever->retrieve($query, ['limit' => 5]));

        $videos = [];
        foreach ($matched as $doc) {
            $id = (string) $doc->getId();
            $content = $this->contentRepository->find($id);
            $metadata = $doc->getMetadata();

            $videos[] = [
                'id' => $id,
                'title' => $content?->getTitle() ?? $content?->getFilename() ?? null,
                'summary' => $metadata->hasText() ? $metadata->getText() : null,
            ];
        }

        $result = $this->searchAgent->call(new MessageBag(Message::ofUser($query)));

        if (!$result instanceof TextResult) {
            return $this->json(['ok' => false, 'error' => 'Search failed. Please try again.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'ok' => true,
            'answer' => $result->getContent(),
            'videos' => array_values($videos),
        ]);
    }
}
