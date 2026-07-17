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
        $metadata = new Metadata();
        $metadata->setText($transcript);
        if ('' !== $title) {
            $metadata->setTitle($title);
        }

        // Vectorize the full transcript for best semantic retrieval quality
        $doc = new TextDocument($contentId, $transcript, $metadata);
        $vectorDoc = $this->vectorizer->vectorize($doc);
        $this->store->add($vectorDoc);
    }
}
