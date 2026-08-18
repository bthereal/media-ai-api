<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TranslateCaptionsMessage;
use App\Repository\ContentRepository;
use App\Service\CaptionTranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class TranslateCaptionsHandler
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly CaptionTranslationService $captionTranslationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TranslateCaptionsMessage $message): void
    {
        $content = $this->contentRepository->find($message->contentId);

        if (null === $content) {
            $this->logger->warning('TranslateCaptions: Content not found', ['contentId' => $message->contentId]);

            return;
        }

        $transcription = $content->getTranscription();

        if (null === $transcription || null === $transcription->getSegments()) {
            $this->logger->warning('TranslateCaptions: no transcription segments available', ['contentId' => $message->contentId]);

            return;
        }

        // Another request may have already translated this language while this
        // message sat in the queue — avoid redoing the (slow, billable) work.
        if (null !== $transcription->getTranslation($message->lang)) {
            return;
        }

        // Deliberately not caught here — a batch occasionally drifts off the
        // requested JSON shape (the same class of prompt-adherence flakiness as
        // chapter/tag generation elsewhere in this pipeline), and letting the
        // exception propagate gives Messenger's default retry policy a chance to
        // succeed on a resample before this lands in the failed transport, same
        // as GenerateChaptersHandler/GenerateTagsHandler.
        $translated = $this->captionTranslationService->translate($transcription->getSegments(), $message->lang);

        $transcription->setTranslation($message->lang, $translated);
        $this->entityManager->flush();
    }
}
