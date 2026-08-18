<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Content;
use App\Repository\ContentRepository;
use App\Service\VideoSearchService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class VideoSearchServiceTest extends TestCase
{
    private StoreInterface&MockObject $store;
    private VectorizerInterface&MockObject $vectorizer;
    private ContentRepository&MockObject $contentRepo;
    private VideoSearchService $service;

    protected function setUp(): void
    {
        $this->store = $this->createMock(StoreInterface::class);
        $this->vectorizer = $this->createMock(VectorizerInterface::class);
        $this->contentRepo = $this->createMock(ContentRepository::class);

        $this->service = new VideoSearchService($this->store, $this->vectorizer, $this->contentRepo);
    }

    private function makeContent(string $id): Content&MockObject
    {
        $content = $this->createMock(Content::class);
        $content->method('getId')->willReturn(Uuid::fromString($id));
        $content->method('getDeletedAt')->willReturn(null);

        return $content;
    }

    public function testSearchPassesLimitAndMaxScoreToTheStore(): void
    {
        $vector = new Vector([0.1]);
        $this->vectorizer->method('vectorize')->willReturn($vector);

        $capturedOptions = null;
        $this->store
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(VectorQuery::class))
            ->willReturnCallback(function ($query, $options) use (&$capturedOptions) {
                $capturedOptions = $options;

                return [];
            });

        $this->service->search('some query');

        $this->assertSame(3, $capturedOptions['limit']);
        $this->assertSame(0.28, $capturedOptions['maxScore']);
    }

    public function testSearchReturnsContentAndTextForEachHit(): void
    {
        $this->vectorizer->method('vectorize')->willReturn(new Vector([0.1]));

        $metadata = new Metadata();
        $metadata->setText('Transcript excerpt.');
        $doc = new VectorDocument('550e8400-e29b-41d4-a716-446655440000', new Vector([0.1]), $metadata);

        $this->store->method('query')->willReturn([$doc]);

        $content = $this->makeContent('550e8400-e29b-41d4-a716-446655440000');
        $this->contentRepo
            ->expects($this->once())
            ->method('find')
            ->with('550e8400-e29b-41d4-a716-446655440000')
            ->willReturn($content);

        $results = $this->service->search('some query');

        $this->assertCount(1, $results);
        $this->assertSame($content, $results[0]['content']);
        $this->assertSame('Transcript excerpt.', $results[0]['text']);
    }

    public function testSearchSkipsDocumentsWhoseContentIsMissing(): void
    {
        $this->vectorizer->method('vectorize')->willReturn(new Vector([0.1]));

        $doc = new VectorDocument('550e8400-e29b-41d4-a716-446655440000', new Vector([0.1]), new Metadata());
        $this->store->method('query')->willReturn([$doc]);
        $this->contentRepo->method('find')->willReturn(null);

        $this->assertSame([], $this->service->search('some query'));
    }

    public function testSearchSkipsArchivedContent(): void
    {
        $this->vectorizer->method('vectorize')->willReturn(new Vector([0.1]));

        $doc = new VectorDocument('550e8400-e29b-41d4-a716-446655440000', new Vector([0.1]), new Metadata());
        $this->store->method('query')->willReturn([$doc]);

        $content = $this->createMock(Content::class);
        $content->method('getDeletedAt')->willReturn(new \DateTimeImmutable());
        $this->contentRepo->method('find')->willReturn($content);

        $this->assertSame([], $this->service->search('some query'));
    }
}
