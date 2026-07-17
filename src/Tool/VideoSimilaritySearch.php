<?php

declare(strict_types=1);

namespace App\Tool;

use App\Repository\ContentRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTool(
    name: 'similarity_search',
    description: 'Search the video library for videos relevant to a query using semantic similarity. Returns matching video titles and transcript excerpts.',
)]
class VideoSimilaritySearch
{
    public function __construct(
        #[Autowire(service: 'ai.retriever.video_transcript_embeds')]
        private readonly RetrieverInterface $retriever,
        private readonly ContentRepository $contentRepository,
    ) {
    }

    public function __invoke(string $query): string
    {
        $docs = $this->retriever->retrieve($query, ['limit' => 5]);

        $results = [];
        foreach ($docs as $doc) {
            $id = (string) $doc->getId();
            $content = $this->contentRepository->find($id);
            $title = $content?->getTitle() ?? $content?->getFilename() ?? 'Untitled';

            $metadata = $doc->getMetadata();
            $text = $metadata->hasText() ? ($metadata->getText() ?? '') : '';
            $excerpt = mb_strlen($text) > 300 ? mb_substr($text, 0, 300).'…' : $text;

            $results[] = \sprintf("**%s** (id:%s)\n%s", $title, $id, $excerpt);
        }

        if ([] === $results) {
            return 'No matching videos found in the library.';
        }

        return implode("\n\n", $results);
    }
}
