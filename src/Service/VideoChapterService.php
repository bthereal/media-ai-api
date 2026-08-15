<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VideoChapterService
{
    public function __construct(
        #[Autowire(service: 'ai.agent.video_chapters')]
        private readonly AgentInterface $chaptersAgent,
    ) {
    }

    /**
     * @param list<array{start: float, end: float, text: string}> $segments
     *
     * @return list<array{title: string, startSeconds: float, endSeconds: float}>
     */
    public function generateChapters(array $segments, ?float $duration): array
    {
        if ([] === $segments) {
            return [];
        }

        $result = $this->chaptersAgent->call(new MessageBag(Message::ofUser($this->formatPrompt($segments))));
        assert($result instanceof TextResult);

        return $this->parseChapters($result->getContent(), $duration);
    }

    /**
     * @param list<array{start: float, end: float, text: string}> $segments
     */
    private function formatPrompt(array $segments): string
    {
        $lines = [];
        foreach ($segments as $segment) {
            $text = trim($segment['text']);
            if ('' === $text) {
                continue;
            }
            $lines[] = sprintf('[%s] %s', $this->formatTimestamp($segment['start']), $text);
        }

        return implode("\n", $lines);
    }

    private function formatTimestamp(float $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        $secs = (int) floor(fmod($seconds, 60));

        return sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * Parses and defensively validates the model's JSON response — untrusted output,
     * so malformed shapes are dropped rather than allowed to corrupt stored chapters.
     *
     * @return list<array{title: string, startSeconds: float, endSeconds: float}>
     */
    private function parseChapters(string $raw, ?float $duration): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $cleaned) ?? $cleaned;

        $decoded = json_decode($cleaned, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('video_chapters agent did not return a JSON array: '.$raw);
        }

        $chapters = [];
        foreach ($decoded as $index => $entry) {
            if (!is_array($entry) || !isset($entry['startSeconds'], $entry['endSeconds']) || !is_numeric($entry['startSeconds']) || !is_numeric($entry['endSeconds'])) {
                continue;
            }

            $title = is_string($entry['title'] ?? null) && '' !== trim($entry['title'])
                ? trim($entry['title'])
                : sprintf('Chapter %d', $index + 1);

            $start = max(0.0, (float) $entry['startSeconds']);
            $end = (float) $entry['endSeconds'];
            if (null !== $duration) {
                $start = min($start, $duration);
                $end = min($end, $duration);
            }
            $end = max($end, $start);

            $chapters[] = ['title' => $title, 'startSeconds' => $start, 'endSeconds' => $end];
        }

        usort($chapters, static fn (array $a, array $b): int => $a['startSeconds'] <=> $b['startSeconds']);

        // On long transcripts the model can lose track of the true final timestamp and
        // report a last-chapter boundary well short of the actual duration (observed:
        // a 1621s video with a last chapter ending at 691s). The clamping above only
        // ever pulls timestamps DOWN toward duration, never up — so without this, the
        // tail of the video is left with no chapter covering it at all. Always extend
        // the final chapter out to the real duration to guarantee full coverage.
        if ([] !== $chapters && null !== $duration) {
            $lastIndex = count($chapters) - 1;
            $chapters[$lastIndex]['endSeconds'] = max($chapters[$lastIndex]['endSeconds'], $duration);
        }

        return $chapters;
    }
}
