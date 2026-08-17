<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateTagsMessage;
use App\Repository\ContentRepository;
use App\Service\VideoTaggingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GenerateTagsHandler
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly VideoTaggingService $taggingService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateTagsMessage $message): void
    {
        $content = $this->contentRepository->find($message->contentId);

        if (null === $content) {
            $this->logger->warning('GenerateTags: Content not found', ['contentId' => $message->contentId]);

            return;
        }

        $transcription = $content->getTranscription();

        if (null === $transcription || null === $transcription->getTranscription()) {
            $this->logger->warning('GenerateTags: no completed transcription', ['contentId' => $message->contentId]);

            return;
        }

        $result = $this->taggingService->generateTags($transcription->getTranscription());
        $transcription->setTagsAndCategory($result['tags'], $result['category']);
        $this->entityManager->flush();
    }
}
