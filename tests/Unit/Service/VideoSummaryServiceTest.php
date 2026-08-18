<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\VideoSummaryService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Vector\NullVector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\StoreInterface;

#[AllowMockObjectsWithoutExpectations]
class VideoSummaryServiceTest extends TestCase
{
    private AgentInterface&MockObject $agent;
    private VectorizerInterface&MockObject $vectorizer;
    private StoreInterface&MockObject $store;
    private VideoSummaryService $service;

    protected function setUp(): void
    {
        $this->agent = $this->createMock(AgentInterface::class);
        $this->vectorizer = $this->createMock(VectorizerInterface::class);
        $this->store = $this->createMock(StoreInterface::class);

        $this->service = new VideoSummaryService($this->agent, $this->vectorizer, $this->store);
    }

    public function testSummarizeReturnsTrimmedTextUpTo200Chars(): void
    {
        $this->agent
            ->expects($this->once())
            ->method('call')
            ->with($this->isInstanceOf(MessageBag::class))
            ->willReturn(new TextResult(str_repeat('x', 250)));

        $result = $this->service->summarize('some transcript');

        $this->assertSame(200, mb_strlen($result));
    }

    public function testSummarizeTrimsWhitespace(): void
    {
        $this->agent->method('call')->willReturn(new TextResult("  Hello world.  "));

        $result = $this->service->summarize('transcript');

        $this->assertSame('Hello world.', $result);
    }

    private function makeVectorDoc(string $id = 'test-id'): VectorDocument
    {
        return new VectorDocument($id, new NullVector(), new Metadata());
    }

    public function testEmbedAndStoreCallsVectorizerThenStore(): void
    {
        $vectorDoc = $this->makeVectorDoc();

        $this->vectorizer
            ->expects($this->once())
            ->method('vectorize')
            ->with($this->isInstanceOf(TextDocument::class))
            ->willReturn($vectorDoc);

        $this->store
            ->expects($this->once())
            ->method('add')
            ->with($vectorDoc);

        $this->service->embedAndStore('some-uuid', 'A short summary.', 'My Video');
    }

    public function testEmbedAndStoreUsesContentIdAsDocumentId(): void
    {
        $contentId = '550e8400-e29b-41d4-a716-446655440000';
        $captured = null;

        $this->vectorizer
            ->method('vectorize')
            ->willReturnCallback(function (TextDocument $doc) use (&$captured) {
                $captured = $doc;

                return $this->makeVectorDoc();
            });

        $this->store->method('add');

        $this->service->embedAndStore($contentId, 'Summary text.', '');

        $this->assertInstanceOf(TextDocument::class, $captured);
        $this->assertSame($contentId, $captured->getId());
    }

    public function testEmbedAndStoreWithEmptyTitleOmitsTitleFromMetadata(): void
    {
        $captured = null;

        $this->vectorizer
            ->method('vectorize')
            ->willReturnCallback(function (TextDocument $doc) use (&$captured) {
                $captured = $doc;

                return $this->makeVectorDoc();
            });

        $this->store->method('add');

        $this->service->embedAndStore('uuid', 'Summary.', '');

        $this->assertFalse($captured->getMetadata()->hasTitle());
    }

    public function testEmbedAndStoreTruncatesTranscriptsThatExceedTheEmbeddingModelsContextWindow(): void
    {
        // ~40k chars is well beyond text-embedding-ada-002's 8192-token limit —
        // roughly what a 50+ minute video's transcript looks like, and exactly
        // what the API previously rejected outright with a context-length error.
        $longTranscript = str_repeat('word ', 8_000);
        $captured = null;

        $this->vectorizer
            ->method('vectorize')
            ->willReturnCallback(function (TextDocument $doc) use (&$captured) {
                $captured = $doc;

                return $this->makeVectorDoc();
            });

        $this->store->method('add');

        $this->service->embedAndStore('uuid', $longTranscript, 'Title');

        $this->assertLessThan(mb_strlen($longTranscript), mb_strlen($captured->getContent()));
        $this->assertLessThanOrEqual(28_000, mb_strlen($captured->getContent()));
        $this->assertSame($captured->getContent(), $captured->getMetadata()->getText());
    }

    public function testEmbedAndStoreLeavesShortTranscriptsUntouched(): void
    {
        $captured = null;

        $this->vectorizer
            ->method('vectorize')
            ->willReturnCallback(function (TextDocument $doc) use (&$captured) {
                $captured = $doc;

                return $this->makeVectorDoc();
            });

        $this->store->method('add');

        $this->service->embedAndStore('uuid', 'A short transcript.', 'Title');

        $this->assertSame('A short transcript.', $captured->getContent());
    }

    public function testRemoveEmbeddingCallsStoreRemove(): void
    {
        $this->store
            ->expects($this->once())
            ->method('remove')
            ->with('550e8400-e29b-41d4-a716-446655440000');

        $this->service->removeEmbedding('550e8400-e29b-41d4-a716-446655440000');
    }
}
