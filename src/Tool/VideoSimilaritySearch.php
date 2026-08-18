<?php

declare(strict_types=1);

namespace App\Tool;

use App\Service\VideoSearchService;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    name: 'similarity_search',
    description: 'Search the video library for videos relevant to a query using semantic similarity. Returns matching video titles and transcript excerpts.',
)]
class VideoSimilaritySearch
{
    public function __construct(
        private readonly VideoSearchService $videoSearchService,
    ) {
    }

    public function __invoke(string $query): string
    {
        $hits = $this->videoSearchService->search($query);

        if ([] === $hits) {
            return 'No matching videos found in the library.';
        }

        $results = [];
        foreach ($hits as $hit) {
            $content = $hit['content'];
            $title = $content->getTitle() ?? $content->getFilename();
            $excerpt = mb_strlen($hit['text']) > 300 ? mb_substr($hit['text'], 0, 300) . '…' : $hit['text'];

            $results[] = \sprintf("**%s** (id:%s)\n%s", $title, (string) $content->getId(), $excerpt);
        }

        return implode("\n\n", $results);
    }
}
