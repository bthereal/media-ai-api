<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateCaptionsMessage;
use App\Repository\ContentRepository;
use App\Service\CaptionLanguages;
use App\Service\CaptionTranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Eagerly pre-generates captions for the languages the uploader requested at
 * upload time, using the same translate()/setTranslation() the lazy on-demand
 * path in ContentController::captions() uses — this just runs it upfront instead
 * of waiting for a viewer to request a specific language's .vtt file.
 */
#[AsMessageHandler]
class GenerateCaptionsHandler
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly CaptionTranslationService $captionTranslationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateCaptionsMessage $message): void
    {
        $content = $this->contentRepository->find($message->contentId);

        if (null === $content) {
            $this->logger->warning('GenerateCaptions: Content not found', ['contentId' => $message->contentId]);

            return;
        }

        $transcription = $content->getTranscription();

        if (null === $transcription || null === $transcription->getSegments() || null === $transcription->getLanguage()) {
            $this->logger->warning('GenerateCaptions: no completed transcription', ['contentId' => $message->contentId]);

            return;
        }

        $nativeCode = CaptionLanguages::codeForWhisperLanguage($transcription->getLanguage());
        $segments = $transcription->getSegments();

        foreach ($transcription->getRequestedCaptionLanguages() as $lang) {
            if ($lang === $nativeCode || null !== $transcription->getTranslation($lang)) {
                continue;
            }

            try {
                $translated = $this->captionTranslationService->translate($segments, $lang);
            } catch (\Throwable $e) {
                $this->logger->warning('GenerateCaptions: translation failed', ['contentId' => $message->contentId, 'lang' => $lang, 'error' => $e->getMessage()]);

                continue;
            }

            $transcription->setTranslation($lang, $translated);
        }

        $this->entityManager->flush();
    }
}
