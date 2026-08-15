<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ThumbnailPickerService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

#[AllowMockObjectsWithoutExpectations]
class ThumbnailPickerServiceTest extends TestCase
{
    private AgentInterface&MockObject $agent;
    private ThumbnailPickerService $service;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->agent = $this->createMock(AgentInterface::class);
        $this->service = new ThumbnailPickerService($this->agent);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function makeCandidateFiles(int $count): array
    {
        $files = [];
        for ($i = 0; $i < $count; ++$i) {
            $path = tempnam(sys_get_temp_dir(), 'thumb_test_').'.jpg';
            file_put_contents($path, 'fake-jpeg-bytes');
            $this->tmpFiles[] = $path;
            $files[] = $path;
        }

        return $files;
    }

    public function testPickBestReturnsZeroBasedIndexFromModelResponse(): void
    {
        $candidates = $this->makeCandidateFiles(4);

        $this->agent
            ->expects($this->once())
            ->method('call')
            ->with($this->isInstanceOf(MessageBag::class))
            ->willReturn(new TextResult('3'));

        $result = $this->service->pickBest($candidates);

        $this->assertSame(2, $result);
    }

    public function testPickBestExtractsNumberFromChattyResponse(): void
    {
        $candidates = $this->makeCandidateFiles(4);

        $this->agent->method('call')->willReturn(new TextResult('I think frame 2 is best because it has a clear face.'));

        $result = $this->service->pickBest($candidates);

        $this->assertSame(1, $result);
    }

    public function testPickBestThrowsWhenResponseHasNoNumber(): void
    {
        $candidates = $this->makeCandidateFiles(3);

        $this->agent->method('call')->willReturn(new TextResult('none of these are good'));

        $this->expectException(\RuntimeException::class);

        $this->service->pickBest($candidates);
    }

    public function testPickBestThrowsWhenNumberOutOfRange(): void
    {
        $candidates = $this->makeCandidateFiles(2);

        $this->agent->method('call')->willReturn(new TextResult('7'));

        $this->expectException(\RuntimeException::class);

        $this->service->pickBest($candidates);
    }
}
