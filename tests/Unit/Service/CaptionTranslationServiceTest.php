<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CaptionTranslationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

#[AllowMockObjectsWithoutExpectations]
class CaptionTranslationServiceTest extends TestCase
{
    private AgentInterface&MockObject $agent;
    private CaptionTranslationService $service;

    private const SEGMENTS = [
        ['start' => 0.0, 'end' => 5.0, 'text' => 'Hello.'],
        ['start' => 5.0, 'end' => 10.0, 'text' => 'How are you?'],
    ];

    protected function setUp(): void
    {
        $this->agent = $this->createMock(AgentInterface::class);
        $this->service = new CaptionTranslationService($this->agent);
    }

    public function testTranslateReturnsEmptyArrayForEmptySegments(): void
    {
        $this->agent->expects($this->never())->method('call');

        $this->assertSame([], $this->service->translate([], 'es'));
    }

    public function testTranslatePreservesTimingAndAppliesTranslatedText(): void
    {
        $this->agent
            ->expects($this->once())
            ->method('call')
            ->with($this->isInstanceOf(MessageBag::class))
            ->willReturn(new TextResult('["Hola.", "¿Cómo estás?"]'));

        $result = $this->service->translate(self::SEGMENTS, 'es');

        $this->assertSame(
            [
                ['start' => 0.0, 'end' => 5.0, 'text' => 'Hola.'],
                ['start' => 5.0, 'end' => 10.0, 'text' => '¿Cómo estás?'],
            ],
            $result,
        );
    }

    public function testTranslateStripsMarkdownCodeFences(): void
    {
        $this->agent->method('call')->willReturn(new TextResult("```json\n[\"Hola.\", \"Adios.\"]\n```"));

        $result = $this->service->translate(self::SEGMENTS, 'es');

        $this->assertSame('Hola.', $result[0]['text']);
    }

    public function testTranslateThrowsWhenLineCountMismatches(): void
    {
        $this->agent->method('call')->willReturn(new TextResult('["Hola."]'));

        $this->expectException(\RuntimeException::class);

        $this->service->translate(self::SEGMENTS, 'es');
    }

    public function testTranslateThrowsOnUnparseableResponse(): void
    {
        $this->agent->method('call')->willReturn(new TextResult('not json'));

        $this->expectException(\RuntimeException::class);

        $this->service->translate(self::SEGMENTS, 'es');
    }

    public function testTranslateThrowsWhenLineIsNotAString(): void
    {
        $this->agent->method('call')->willReturn(new TextResult('["Hola.", 42]'));

        $this->expectException(\RuntimeException::class);

        $this->service->translate(self::SEGMENTS, 'es');
    }
}
