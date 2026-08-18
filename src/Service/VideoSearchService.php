<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Content;
use App\Repository\ContentRepository;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shared semantic-search core for both SearchController (the "videos" cards
 * shown to the viewer) and VideoSimilaritySearch (the tool the video_search
 * agent calls to compose its answer) — both need identical relevance
 * filtering, so the threshold/limit live in exactly one place.
 */
class VideoSearchService
{
    private const int LIMIT = 3;

    /**
     * Cosine-distance cutoff — anything past this is treated as unrelated rather
     * than padding out the result count. Calibrated against this library's real
     * embeddings: for on-topic queries, genuinely relevant videos consistently
     * scored ≤0.28, with a clear gap before unrelated content started (~0.30+).
     * Not embedded in the store setup — this reflects text-embedding-ada-002's
     * distance behavior, not something that changes per deployment.
     */
    private const float MAX_DISTANCE = 0.28;

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.video_transcript_embeds')]
        private readonly StoreInterface $store,
        #[Autowire(service: 'ai.vectorizer.openai_ada')]
        private readonly VectorizerInterface $vectorizer,
        private readonly ContentRepository $contentRepository,
    ) {
    }

    /**
     * @return list<array{content: Content, text: string}>
     */
    public function search(string $query): array
    {
        // Deliberately a plain VectorQuery, not a hybrid search — hybrid requires
        // only a stray keyword match (via Postgres full-text search) to include a
        // document at all, which let clearly unrelated videos into results as
        // long as some common word overlapped somewhere in their transcript.
        // Pure semantic distance + a real cutoff is what actually captures
        // "relevant."
        $vector = $this->vectorizer->vectorize($query);
        assert($vector instanceof Vector);

        $docs = $this->store->query(new VectorQuery($vector), [
            'limit' => self::LIMIT,
            'maxScore' => self::MAX_DISTANCE,
        ]);

        $results = [];
        foreach ($docs as $doc) {
            $content = $this->contentRepository->find((string) $doc->getId());

            // Defense in depth: archived content's embeddings are removed when it's
            // deleted, but skip defensively in case a stale entry ever slips through.
            if (null === $content || null !== $content->getDeletedAt()) {
                continue;
            }

            $metadata = $doc->getMetadata();

            $results[] = [
                'content' => $content,
                'text' => $metadata->hasText() ? ($metadata->getText() ?? '') : '',
            ];
        }

        return $results;
    }
}
