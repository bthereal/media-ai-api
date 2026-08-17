<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateChaptersMessage;
use App\Repository\ContentRepository;
use App\Service\VideoChapterService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GenerateChaptersHandler
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly VideoChapterService $chapterService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateChaptersMessage $message): void
    {
        $content = $this->contentRepository->find($message->contentId);

        if (null === $content) {
            $this->logger->warning('GenerateChapters: Content not found', ['contentId' => $message->contentId]);

            return;
        }

        $transcription = $content->getTranscription();

        if (null === $transcription || null === $transcription->getSegments()) {
            $this->logger->warning('GenerateChapters: no transcription segments available', ['contentId' => $message->contentId]);

            return;
        }

        $chapters = $this->chapterService->generateChapters($transcription->getSegments(), $content->getDuration());
        $transcription->setChapters($chapters);
        $this->entityManager->flush();
    }
}
