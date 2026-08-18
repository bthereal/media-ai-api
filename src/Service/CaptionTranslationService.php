<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class CaptionTranslationService
{
    /**
     * Segments per translation call. Long videos can have hundreds of Whisper
     * segments; asking the model to preserve an exact 1:1 array length across all
     * of them in one shot is where it reliably starts merging/splitting/dropping
     * lines (observed: JSON responses with the wrong element count on ~10+ minute
     * videos). Batching keeps each call's array small enough that the model holds
     * the line count exactly, and caps the blast radius of a single bad batch.
     *
     * Batches run sequentially — a ~300-segment video is ~15 batches, tens of
     * seconds total. That's fine for the background job that calls this (see
     * TranslateCaptionsHandler); it's NOT fine for a synchronous HTTP response,
     * which is exactly why this service is invoked from a message handler rather
     * than inline in the request that serves a .vtt file.
     *
     * Kept fairly small (not just "big enough to cut round-trips") because
     * Whisper segments are often short, comma-fragmented mid-sentence clauses —
     * the model tends to merge two adjacent short fragments into one fluent
     * translated line, which is exactly what breaks the 1:1 count requirement.
     * A smaller array gives it less opportunity to do that per call, and
     * Messenger's retry (see TranslateCaptionsHandler) resamples on failure.
     */
    private const int BATCH_SIZE = 20;

    public function __construct(
        #[Autowire(service: 'ai.agent.video_translator')]
        private readonly AgentInterface $translatorAgent,
    ) {
    }

    /**
     * Translates segment text into the target language, preserving each segment's
     * original start/end timing. Internally batched — see BATCH_SIZE.
     *
     * @param list<array{start: float, end: float, text: string}> $segments
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    public function translate(array $segments, string $targetLangCode): array
    {
        if ([] === $segments) {
            return [];
        }

        $targetLanguageLabel = CaptionLanguages::labelFor($targetLangCode);

        $translated = [];
        foreach (array_chunk($segments, self::BATCH_SIZE) as $batch) {
            $lines = array_map(static fn (array $segment): string => $segment['text'], $batch);
            $translatedLines = $this->translateBatch($lines, $targetLanguageLabel);

            foreach ($batch as $index => $segment) {
                $translated[] = [
                    'start' => $segment['start'],
                    'end' => $segment['end'],
                    'text' => $translatedLines[$index],
                ];
            }
        }

        return $translated;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function translateBatch(array $lines, string $targetLanguageLabel): array
    {
        $prompt = sprintf(
            "Target language: %s\n\n%s",
            $targetLanguageLabel,
            json_encode($lines, \JSON_UNESCAPED_UNICODE),
        );

        $result = $this->translatorAgent->call(new MessageBag(Message::ofUser($prompt)));
        assert($result instanceof TextResult);

        return $this->parseTranslatedLines($result->getContent(), count($lines));
    }

    /**
     * @return list<string>
     */
    private function parseTranslatedLines(string $raw, int $expectedCount): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $cleaned) ?? $cleaned;

        $decoded = json_decode($cleaned, true);

        if (!is_array($decoded) || count($decoded) !== $expectedCount) {
            throw new \RuntimeException(sprintf(
                'video_translator agent returned %d lines, expected %d: "%s"',
                is_array($decoded) ? count($decoded) : -1,
                $expectedCount,
                $raw,
            ));
        }

        foreach ($decoded as $line) {
            if (!is_string($line)) {
                throw new \RuntimeException('video_translator agent returned a non-string line: ' . $raw);
            }
        }

        return array_values($decoded);
    }
}
