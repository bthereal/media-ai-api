<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\EmbedVideoSummaryMessage;
use App\Repository\ContentRepository;
use App\Service\VideoSummaryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class EmbedVideoSummaryHandler
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly VideoSummaryService $summaryService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EmbedVideoSummaryMessage $message): void
    {
        $content = $this->contentRepository->find($message->contentId);

        if (null === $content) {
            $this->logger->warning('EmbedVideoSummary: Content not found', ['contentId' => $message->contentId]);

            return;
        }

        $transcription = $content->getTranscription();

        if (null === $transcription || null === $transcription->getTranscription()) {
            $this->logger->warning('EmbedVideoSummary: no completed transcription', ['contentId' => $message->contentId]);

            return;
        }

        $this->summaryService->embedAndStore(
            contentId: $message->contentId,
            transcript: $transcription->getTranscription(),
            title: $content->getTitle() ?? $content->getFilename(),
        );
    }
}
