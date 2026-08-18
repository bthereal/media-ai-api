<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Content;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ChunkUploadControllerTest extends WebTestCase
{
    private const string VALID_UUID = '550e8400-e29b-41d4-a716-446655440000';
    private const string ENDPOINT = '/api/upload/chunk';

    private string $tmpFile;
    private EntityManagerInterface $em;
    private ?string $assembledPath = null;

    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE content, video_transcription RESTART IDENTITY CASCADE');

        $this->tmpFile = tempnam(sys_get_temp_dir(), 'chunk_func_');
        file_put_contents($this->tmpFile, str_repeat('x', 1024));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }

        if (null !== $this->assembledPath) {
            $filesystem = static::getContainer()->get(FilesystemOperator::class);
            if ($filesystem->fileExists($this->assembledPath)) {
                $filesystem->delete($this->assembledPath);
            }
        }

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE content, video_transcription RESTART IDENTITY CASCADE');

        parent::tearDown();
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
        $client = static::getClient();

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
        $client = static::getClient();
        $client->request('POST', self::ENDPOINT, $this->validParams());

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($body['ok']);
    }

    public function testInvalidUuidReturns400(): void
    {
        $client = static::getClient();
        $client->request('POST', self::ENDPOINT, $this->validParams(['uploadId' => 'bad-id']), ['chunk' => $this->makeUploadedFile()]);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testNonMp4FilenameReturns400(): void
    {
        $client = static::getClient();
        $client->request('POST', self::ENDPOINT, $this->validParams(['filename' => 'video.avi']), ['chunk' => $this->makeUploadedFile()]);

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testNonFinalChunkReturnsOkWithoutAssembly(): void
    {
        $client = static::getClient();
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

    public function testFinalChunkOfNewUploadSetsOwnerIdFromAuthenticatedUser(): void
    {
        $client = static::getClient();
        $uuid = '880e8400-e29b-41d4-a716-446655440003';
        $this->assembledPath = "{$uuid}/video.mp4";

        $client->request('POST', self::ENDPOINT, $this->validParams([
            'uploadId' => $uuid,
            'chunkIndex' => '0',
            'totalChunks' => '1',
        ]), ['chunk' => $this->makeUploadedFile()]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);

        $this->em->clear();
        $content = $this->em->getRepository(Content::class)->find($body['contentId']);
        $this->assertNotNull($content);
        // No JWT is sent by the test client, so this mirrors the existing
        // 'anonymous' owner-fallback convention used for Playlist ownership.
        $this->assertSame('anonymous', $content->getOwnerId());
    }

    public function testFinalChunkOfArchivedDuplicateRevivesInsteadOfCreatingNewRow(): void
    {
        // The assembled file's bytes are just the single chunk's bytes verbatim,
        // so its hash is known up front and can be used to seed an archived Content.
        $fileHash = hash('sha256', str_repeat('x', 1024));

        $archived = new Content(
            filename: 'video.mp4',
            uploadId: 'aa0e8400-e29b-41d4-a716-446655440099',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: $fileHash,
        );
        $archived->archive();
        $this->em->persist($archived);
        $this->em->flush();
        $archivedId = (string) $archived->getId();

        $client = static::getClient();
        $uuid = '770e8400-e29b-41d4-a716-446655440002';
        $this->assembledPath = "{$uuid}/video.mp4";

        $client->request('POST', self::ENDPOINT, $this->validParams([
            'uploadId' => $uuid,
            'chunkIndex' => '0',
            'totalChunks' => '1',
        ]), ['chunk' => $this->makeUploadedFile()]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertTrue($body['duplicate']);
        $this->assertSame($archivedId, $body['contentId']);

        $this->em->clear();
        $revived = $this->em->getRepository(Content::class)->find($archivedId);
        $this->assertNotNull($revived);
        $this->assertNull($revived->getDeletedAt());

        $countAfter = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM content');
        $this->assertSame(1, $countAfter);
    }
}
