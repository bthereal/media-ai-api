<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Content;
use App\Entity\VideoTranscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Vector\NullVector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ContentControllerTest extends WebTestCase
{
    private const ENDPOINT = '/api/content';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        static::createClient();

        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->truncateTables();
    }

    protected function tearDown(): void
    {
        $this->truncateTables();
        parent::tearDown();
    }

    private function truncateTables(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('TRUNCATE TABLE content, video_transcription RESTART IDENTITY CASCADE');
        $conn->executeStatement('TRUNCATE TABLE video_transcript_embeds');
    }

    public function testListReturnsEmptyPage(): void
    {
        $client = static::getClient();
        $client->request('GET', self::ENDPOINT);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame([], $body['items']);
        $this->assertSame(0, $body['total']);
        $this->assertSame(1, $body['page']);
        $this->assertSame(12, $body['perPage']);
        $this->assertSame(1, $body['totalPages']);
        $this->assertFalse($body['hasNext']);
        $this->assertFalse($body['hasPrev']);
        $this->assertSame([], $body['availableCategories']);
    }

    public function testListFiltersByCategoryAndExposesAvailableCategories(): void
    {
        $tutorial = new VideoTranscription('550e8400-e29b-41d4-a716-446655440000', 'tutorial.mp4');
        $tutorial->markCompleted('Transcript.');
        $tutorial->setTagsAndCategory(['ai'], 'Tutorial');

        $interview = new VideoTranscription('660e8400-e29b-41d4-a716-446655440001', 'interview.mp4');
        $interview->markCompleted('Transcript.');
        $interview->setTagsAndCategory(['guest'], 'Interview');

        $tutorialContent = new Content(
            filename: 'tutorial.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('t', 64),
        );
        $tutorialContent->setTranscription($tutorial);

        $interviewContent = new Content(
            filename: 'interview.mp4',
            uploadId: '660e8400-e29b-41d4-a716-446655440001',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('v', 64),
        );
        $interviewContent->setTranscription($interview);

        $this->em->persist($tutorialContent);
        $this->em->persist($interviewContent);
        $this->em->flush();

        $client = static::getClient();

        // Unfiltered: both present, both categories listed as available
        $client->request('GET', self::ENDPOINT);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(2, $body['items']);
        $this->assertSame(['Interview', 'Tutorial'], $body['availableCategories']);

        // Filtered: only the matching video
        $client->request('GET', self::ENDPOINT.'?category=Tutorial');
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(1, $body['items']);
        $this->assertSame('tutorial.mp4', $body['items'][0]['filename']);
        $this->assertSame(1, $body['total']);
        // availableCategories always reflects the whole library, not just the filtered slice
        $this->assertSame(['Interview', 'Tutorial'], $body['availableCategories']);
    }

    public function testListReturnsPaginatedResults(): void
    {
        for ($i = 1; $i <= 14; ++$i) {
            $content = new Content(
                filename: "video{$i}.mp4",
                uploadId: sprintf('550e8400-e29b-41d4-a716-%012d', $i),
                mimeType: 'video/mp4',
                fileSize: 1024 * $i,
                fileHash: str_pad((string) $i, 64, '0', \STR_PAD_LEFT),
            );
            $this->em->persist($content);
        }
        $this->em->flush();

        $client = static::getClient();

        $client->request('GET', self::ENDPOINT.'?page=1');
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertTrue($body['ok']);
        $this->assertCount(12, $body['items']);
        $this->assertSame(14, $body['total']);
        $this->assertSame(1, $body['page']);
        $this->assertSame(2, $body['totalPages']);
        $this->assertTrue($body['hasNext']);
        $this->assertFalse($body['hasPrev']);

        $client->request('GET', self::ENDPOINT.'?page=2');
        $body2 = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(2, $body2['items']);
        $this->assertSame(2, $body2['page']);
        $this->assertFalse($body2['hasNext']);
        $this->assertTrue($body2['hasPrev']);
    }

    public function testListItemsAreOrderedByCreatedAtDesc(): void
    {
        $first = new Content(
            filename: 'first.mp4',
            uploadId: '550e8400-e29b-41d4-a716-000000000001',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('1', 64),
        );

        $second = new Content(
            filename: 'second.mp4',
            uploadId: '550e8400-e29b-41d4-a716-000000000002',
            mimeType: 'video/mp4',
            fileSize: 2048,
            fileHash: str_repeat('2', 64),
        );

        // Set explicit timestamps so DESC ordering is deterministic
        $tsProp = new \ReflectionProperty(Content::class, 'createdAt');
        $tsProp->setValue($first, new \DateTimeImmutable('2024-01-01 10:00:00'));
        $tsProp->setValue($second, new \DateTimeImmutable('2024-01-02 10:00:00'));

        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', self::ENDPOINT);
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame('second.mp4', $body['items'][0]['filename']);
        $this->assertSame('first.mp4', $body['items'][1]['filename']);
    }

    public function testGetReturnsNotFoundForUnknownId(): void
    {
        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($body['ok']);
        $this->assertArrayHasKey('error', $body);
    }

    public function testGetReturnsContentWithoutTranscription(): void
    {
        $content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1048576,
            fileHash: str_repeat('a', 64),
            duration: 30.5,
        );
        $this->em->persist($content);
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/'.(string) $content->getId());

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame((string) $content->getId(), $body['id']);
        $this->assertSame('video.mp4', $body['filename']);
        $this->assertSame('video/mp4', $body['mimeType']);
        $this->assertSame(1048576, $body['fileSize']);
        $this->assertSame(30.5, $body['duration']);
        $this->assertNull($body['transcription']);
    }

    public function testGetReturnsContentWithCompletedTranscription(): void
    {
        $transcription = new VideoTranscription('550e8400-e29b-41d4-a716-446655440000', 'video.mp4');
        $transcription->markCompleted('Hello world from the video.');

        $content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 2097152,
            fileHash: str_repeat('b', 64),
        );
        $content->setTranscription($transcription);
        $this->em->persist($content);
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/'.(string) $content->getId());

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertIsArray($body['transcription']);
        $this->assertSame('completed', $body['transcription']['status']);
        $this->assertSame('Hello world from the video.', $body['transcription']['text']);
        $this->assertNotNull($body['transcription']['completedAt']);
    }

    public function testGetReturnsContentWithPendingTranscription(): void
    {
        $transcription = new VideoTranscription('660e8400-e29b-41d4-a716-446655440001', 'other.mp4');

        $content = new Content(
            filename: 'other.mp4',
            uploadId: '660e8400-e29b-41d4-a716-446655440001',
            mimeType: 'video/mp4',
            fileSize: 512000,
            fileHash: str_repeat('c', 64),
        );
        $content->setTranscription($transcription);
        $this->em->persist($content);
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/'.(string) $content->getId());

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSame('pending', $body['transcription']['status']);
        $this->assertNull($body['transcription']['text']);
        $this->assertNull($body['transcription']['completedAt']);
    }

    public function testStreamReturns404ForUnknownId(): void
    {
        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/550e8400-e29b-41d4-a716-446655440000/stream');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testStreamServesFileWithCorrectHeaders(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'stream_test_');
        file_put_contents($tmpFile, 'fake-mp4-content');

        $uploadId = '770e8400-e29b-41d4-a716-446655440002';
        $filename = 'test.mp4';

        // Place a fake file where the controller will look for it
        $uploadsDir = static::getContainer()->getParameter('kernel.project_dir').'/var/uploads';
        @mkdir("{$uploadsDir}/{$uploadId}", 0777, true);
        file_put_contents("{$uploadsDir}/{$uploadId}/{$filename}", 'fake-mp4-content');

        $content = new Content(
            filename: $filename,
            uploadId: $uploadId,
            mimeType: 'video/mp4',
            fileSize: 16,
            fileHash: str_repeat('d', 64),
        );
        $this->em->persist($content);
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/'.(string) $content->getId().'/stream');

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('video/mp4', $response->headers->get('Content-Type') ?? '');

        // Clean up
        unlink("{$uploadsDir}/{$uploadId}/{$filename}");
        rmdir("{$uploadsDir}/{$uploadId}");
        unlink($tmpFile);
    }

    public function testCaptionsReturns404ForUnknownContent(): void
    {
        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/550e8400-e29b-41d4-a716-446655440000/captions/en.vtt');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCaptionsReturns404WhenTranscriptionHasNoSegments(): void
    {
        $content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('e', 64),
        );
        $this->em->persist($content);
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/'.(string) $content->getId().'/captions/en.vtt');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCaptionsServesNativeLanguageAsWebVtt(): void
    {
        $transcription = new VideoTranscription('550e8400-e29b-41d4-a716-446655440000', 'video.mp4');
        $transcription->markCompleted(
            'Hello world.',
            [['start' => 0.0, 'end' => 5.0, 'text' => 'Hello world.']],
            'english',
        );

        $content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('f', 64),
        );
        $content->setTranscription($transcription);
        $this->em->persist($content);
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/'.(string) $content->getId().'/captions/en.vtt');

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/vtt', $response->headers->get('Content-Type') ?? '');
        $this->assertStringStartsWith('WEBVTT', $response->getContent());
        $this->assertStringContainsString('Hello world.', $response->getContent());
        $this->assertStringContainsString('00:00:00.000 --> 00:00:05.000', $response->getContent());
    }

    public function testCaptionsReturns404ForUnsupportedLanguage(): void
    {
        $transcription = new VideoTranscription('550e8400-e29b-41d4-a716-446655440000', 'video.mp4');
        $transcription->markCompleted('Hello.', [['start' => 0.0, 'end' => 5.0, 'text' => 'Hello.']], 'english');

        $content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('g', 64),
        );
        $content->setTranscription($transcription);
        $this->em->persist($content);
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/'.(string) $content->getId().'/captions/xx.vtt');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testRelatedReturns404ForUnknownContent(): void
    {
        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/550e8400-e29b-41d4-a716-446655440000/related');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testRelatedReturnsEmptyListWhenTranscriptionNotCompleted(): void
    {
        $content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('h', 64),
        );
        $this->em->persist($content);
        $this->em->flush();

        // No retriever override: if the controller called it despite no completed
        // transcript, this would hit the real (unconfigured) service and error out.
        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/'.(string) $content->getId().'/related');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame([], $body['items']);
    }

    public function testRelatedExcludesSelfAndSkipsContentMissingFromDb(): void
    {
        $transcription = new VideoTranscription('550e8400-e29b-41d4-a716-446655440000', 'video.mp4');
        $transcription->markCompleted('A video about pgvector semantic search.', [], 'english');

        $content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('i', 64),
        );
        $content->setTranscription($transcription);
        $this->em->persist($content);

        $other = new Content(
            filename: 'other.mp4',
            uploadId: '660e8400-e29b-41d4-a716-446655440001',
            mimeType: 'video/mp4',
            fileSize: 2048,
            fileHash: str_repeat('j', 64),
        );
        $this->em->persist($other);
        $this->em->flush();

        $selfId = (string) $content->getId();
        $otherId = (string) $other->getId();
        $missingId = '770e8400-e29b-41d4-a716-446655440002';

        $this->setRetriever([
            new VectorDocument($selfId, new NullVector(), new Metadata()),
            new VectorDocument($missingId, new NullVector(), new Metadata()),
            new VectorDocument($otherId, new NullVector(), new Metadata()),
        ]);

        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/'.$selfId.'/related');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertCount(1, $body['items']);
        $this->assertSame($otherId, $body['items'][0]['id']);
        $this->assertSame('other.mp4', $body['items'][0]['filename']);
    }

    public function testRelatedCapsAtFiveResults(): void
    {
        $transcription = new VideoTranscription('550e8400-e29b-41d4-a716-446655440000', 'video.mp4');
        $transcription->markCompleted('A video about pgvector semantic search.', [], 'english');

        $content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('k', 64),
        );
        $content->setTranscription($transcription);
        $this->em->persist($content);

        $docs = [];
        for ($i = 1; $i <= 7; ++$i) {
            $other = new Content(
                filename: "other{$i}.mp4",
                uploadId: sprintf('660e8400-e29b-41d4-a716-%012d', $i),
                mimeType: 'video/mp4',
                fileSize: 1024,
                fileHash: str_pad("k{$i}", 64, '0', \STR_PAD_LEFT),
            );
            $this->em->persist($other);
            $this->em->flush();
            $docs[] = new VectorDocument((string) $other->getId(), new NullVector(), new Metadata());
        }
        $this->em->flush();

        $this->setRetriever($docs);

        $client = static::getClient();
        $client->request('GET', self::ENDPOINT.'/'.(string) $content->getId().'/related');

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(5, $body['items']);
    }

    /**
     * @param list<VectorDocument> $docs
     */
    private function setRetriever(array $docs): void
    {
        static::getContainer()->set('ai.retriever.video_transcript_embeds', new class($docs) implements RetrieverInterface {
            /**
             * @param list<VectorDocument> $docs
             */
            public function __construct(private readonly array $docs)
            {
            }

            public function retrieve(string $query, array $options = []): iterable
            {
                return $this->docs;
            }
        });
    }

    public function testDeleteRemovesVectorEmbedding(): void
    {
        $content = new Content(
            filename: 'video.mp4',
            uploadId: '550e8400-e29b-41d4-a716-446655440000',
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_repeat('m', 64),
        );
        $this->em->persist($content);
        $this->em->flush();

        $contentId = (string) $content->getId();
        $this->insertFakeEmbedding($contentId);

        $countBefore = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video_transcript_embeds WHERE id = :id', ['id' => $contentId]);
        $this->assertSame(1, $countBefore);

        $client = static::getClient();
        $client->request('DELETE', self::ENDPOINT.'/'.$contentId);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $countAfter = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video_transcript_embeds WHERE id = :id', ['id' => $contentId]);
        $this->assertSame(0, $countAfter);
    }

    private function insertFakeEmbedding(string $contentId): void
    {
        $vector = '['.implode(',', array_fill(0, 1536, 0.001)).']';
        $this->em->getConnection()->executeStatement(
            'INSERT INTO video_transcript_embeds (id, metadata, embedding) VALUES (:id, :metadata, :embedding)',
            ['id' => $contentId, 'metadata' => '{}', 'embedding' => $vector],
        );
    }
}
