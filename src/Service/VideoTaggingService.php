<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VideoTaggingService
{
    private const int MAX_TAGS = 8;

    public function __construct(
        #[Autowire(service: 'ai.agent.video_tagger')]
        private readonly AgentInterface $taggerAgent,
    ) {
    }

    /**
     * @return array{category: ?string, tags: list<string>}
     */
    public function generateTags(string $transcript): array
    {
        if ('' === trim($transcript)) {
            return ['category' => null, 'tags' => []];
        }

        $result = $this->taggerAgent->call(new MessageBag(Message::ofUser($transcript)));
        assert($result instanceof TextResult);

        return $this->parseResponse($result->getContent());
    }

    /**
     * Parses and defensively validates the model's JSON response — untrusted output,
     * so malformed shapes are dropped rather than allowed to corrupt stored data.
     *
     * @return array{category: ?string, tags: list<string>}
     */
    private function parseResponse(string $raw): array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $cleaned) ?? $cleaned;

        $decoded = json_decode($cleaned, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('video_tagger agent did not return a JSON object: '.$raw);
        }

        $category = isset($decoded['category']) && is_string($decoded['category']) && '' !== trim($decoded['category'])
            ? trim($decoded['category'])
            : null;

        $tags = [];
        if (isset($decoded['tags']) && is_array($decoded['tags'])) {
            foreach ($decoded['tags'] as $tag) {
                if (is_string($tag) && '' !== trim($tag)) {
                    $tags[] = trim($tag);
                }
                if (count($tags) >= self::MAX_TAGS) {
                    break;
                }
            }
        }

        return ['category' => $category, 'tags' => array_values(array_unique($tags))];
    }
}
