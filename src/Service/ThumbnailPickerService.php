<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ThumbnailPickerService
{
    public function __construct(
        #[Autowire(service: 'ai.agent.thumbnail_picker')]
        private readonly AgentInterface $pickerAgent,
    ) {
    }

    /**
     * @param list<string> $candidatePaths absolute paths to candidate JPEGs, in chronological order
     *
     * @return int the index of the best candidate
     */
    public function pickBest(array $candidatePaths): int
    {
        $count = count($candidatePaths);
        $prompt = sprintf(
            'Here are %d candidate thumbnail frames from a video, numbered 1 to %d in the order shown. '
            .'Pick the single frame that best represents the video and would make the most compelling thumbnail — '
            .'prefer frames with faces, readable on-screen text, or high visual contrast, and avoid black, blank, '
            .'blurry, or mid-transition frames. Respond with ONLY the number of the best frame, nothing else.',
            $count,
            $count,
        );

        $images = array_map(static fn (string $path) => Image::fromFile($path), $candidatePaths);

        $result = $this->pickerAgent->call(new MessageBag(Message::ofUser($prompt, ...$images)));
        assert($result instanceof TextResult);

        return $this->parseIndex($result->getContent(), $count);
    }

    private function parseIndex(string $raw, int $count): int
    {
        if (1 === preg_match('/\d+/', $raw, $matches)) {
            $number = (int) $matches[0];
            if ($number >= 1 && $number <= $count) {
                return $number - 1;
            }
        }

        throw new \RuntimeException(sprintf('thumbnail_picker agent returned an unparseable response: "%s"', $raw));
    }
}
