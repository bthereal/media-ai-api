<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\VideoTranscription;
use App\Message\EmbedVideoSummaryMessage;
use App\Message\TranscribeVideoMessage;
use App\Repository\ContentRepository;
use App\Service\VideoTranscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class TranscribeVideoHandler
{
    public function __construct(
        private readonly VideoTranscriptionService $transcriptionService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentRepository $contentRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(TranscribeVideoMessage $message): void
    {
        $repo = $this->entityManager->getRepository(VideoTranscription::class);

        /** @var VideoTranscription|null $record */
        $record = $repo->findOneBy([
            'uploadId' => $message->uploadId,
            'filename' => $message->filename,
        ]);

        if (null === $record) {
            return;
        }

        $record->markProcessing();
        $this->entityManager->flush();

        try {
            $transcription = $this->transcriptionService->transcribe(
                $message->uploadId,
                $message->filename,
            );
            $record->markCompleted($transcription);
        } catch (\Throwable $e) {
            $record->markFailed($e->getMessage());
            $this->entityManager->flush();
            throw $e;
        }

        $this->entityManager->flush();

        $content = $this->contentRepository->findOneBy(['uploadId' => $message->uploadId]);
        if (null !== $content) {
            $this->messageBus->dispatch(new EmbedVideoSummaryMessage((string) $content->getId()));
        }
    }
}
