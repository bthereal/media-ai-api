<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VideoSummaryService
{
    /**
     * text-embedding-ada-002's hard limit is 8192 tokens. There's no tokenizer
     * available here to count exactly, so this uses the standard ~4
     * chars-per-token approximation for English text with a comfortable margin
     * (targets ~7000 tokens, not 8192) — good enough to guarantee we stay under
     * the real limit without needing a tokenizer dependency just for this.
     */
    private const int MAX_EMBED_CHARS = 28_000;

    public function __construct(
        #[Autowire(service: 'ai.agent.video_summarizer')]
        private readonly AgentInterface $summarizerAgent,
        #[Autowire(service: 'ai.vectorizer.openai_ada')]
        private readonly VectorizerInterface $vectorizer,
        #[Autowire(service: 'ai.store.postgres.video_transcript_embeds')]
        private readonly StoreInterface $store,
    ) {
    }

    public function summarize(string $transcript): string
    {
        $result = $this->summarizerAgent->call(new MessageBag(Message::ofUser($transcript)));
        assert($result instanceof TextResult);

        return mb_substr(trim($result->getContent()), 0, 200);
    }

    public function embedAndStore(string $contentId, string $transcript, string $title): void
    {
        // Vectorize as much of the transcript as fits the embedding model's
        // context window — long videos (an hour+) comfortably exceed it, and the
        // model rejects the request outright rather than truncating for us.
        $embeddableText = mb_substr($transcript, 0, self::MAX_EMBED_CHARS);

        $metadata = new Metadata();
        $metadata->setText($embeddableText);
        if ('' !== $title) {
            $metadata->setTitle($title);
        }

        $doc = new TextDocument($contentId, $embeddableText, $metadata);
        $vectorDoc = $this->vectorizer->vectorize($doc);
        $this->store->add($vectorDoc);
    }

    /**
     * Removes a video's embedding from the vector store — called when its Content
     * is archived, so a deleted video stops surfacing in semantic search results.
     */
    public function removeEmbedding(string $contentId): void
    {
        $this->store->remove($contentId);
    }
}
