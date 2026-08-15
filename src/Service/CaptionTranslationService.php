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
    public function __construct(
        #[Autowire(service: 'ai.agent.video_translator')]
        private readonly AgentInterface $translatorAgent,
    ) {
    }

    /**
     * Translates segment text into the target language in a single call, preserving
     * each segment's original start/end timing.
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
        $lines = array_map(static fn (array $segment): string => $segment['text'], $segments);

        $prompt = sprintf(
            "Target language: %s\n\n%s",
            $targetLanguageLabel,
            json_encode(array_values($lines), \JSON_UNESCAPED_UNICODE),
        );

        $result = $this->translatorAgent->call(new MessageBag(Message::ofUser($prompt)));
        assert($result instanceof TextResult);

        $translatedLines = $this->parseTranslatedLines($result->getContent(), count($segments));

        $translated = [];
        foreach ($segments as $index => $segment) {
            $translated[] = [
                'start' => $segment['start'],
                'end' => $segment['end'],
                'text' => $translatedLines[$index],
            ];
        }

        return $translated;
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
                throw new \RuntimeException('video_translator agent returned a non-string line: '.$raw);
            }
        }

        return array_values($decoded);
    }
}
