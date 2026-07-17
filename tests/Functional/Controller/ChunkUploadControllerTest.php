<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ChunkUploadControllerTest extends WebTestCase
{
    private const string VALID_UUID = '550e8400-e29b-41d4-a716-446655440000';
    private const string ENDPOINT = '/api/upload/chunk';

    private string $tmpFile;

    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'chunk_func_');
        file_put_contents($this->tmpFile, str_repeat('x', 1024));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    private function makeUploadedFile(): UploadedFile
    {
        return new UploadedFile($this->tmpFile, 'video.mp4', 'video/mp4', null, true);
    }

    private function validParams(array $overrides = []): array
    {
        return array_merge([
            'uploadId' => self::VALID_UUID,
            'chunkIndex' => '0',
            'totalChunks' => '1',
            'filename' => 'video.mp4',
        ], $overrides);
    }

    public function testHappyPathSingleChunk(): void
    {
        $client = static::createClient();

        // Use totalChunks=2 so assembly is NOT triggered — avoids DB/Messenger dependency in this test
        $client->request('POST', self::ENDPOINT, $this->validParams(['totalChunks' => '2']), ['chunk' => $this->makeUploadedFile()]);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame(self::VALID_UUID, $body['uploadId']);
        $this->assertSame(0, $body['chunkIndex']);
    }

    public function testMissingChunkFileReturns400(): void
    {
        $client = static::createClient();
        $client->request('POST', self::ENDPOINT, $this->validParams());

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($body['ok']);
    }

    public function testInvalidUuidReturns400(): void
    {
        $client = static::createClient();
        $client->request('POST', self::ENDPOINT, $this->validParams(['uploadId' => 'bad-id']), ['chunk' => $this->makeUploadedFile()]);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testNonMp4FilenameReturns400(): void
    {
        $client = static::createClient();
        $client->request('POST', self::ENDPOINT, $this->validParams(['filename' => 'video.avi']), ['chunk' => $this->makeUploadedFile()]);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testNonFinalChunkReturnsOkWithoutAssembly(): void
    {
        $client = static::createClient();
        $uuid = '660e8400-e29b-41d4-a716-446655440001';

        $client->request('POST', self::ENDPOINT, $this->validParams([
            'uploadId' => $uuid,
            'chunkIndex' => '0',
            'totalChunks' => '2',
        ]), ['chunk' => $this->makeUploadedFile()]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame(0, $body['chunkIndex']);
    }
}
