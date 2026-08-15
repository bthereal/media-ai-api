<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Content;
use App\Entity\WatchEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AnalyticsControllerTest extends WebTestCase
{
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
        $conn->executeStatement('TRUNCATE TABLE watch_event, content, video_transcription RESTART IDENTITY CASCADE');
    }

    private function createContent(string $filename, string $fileHashSeed, ?float $duration = null): Content
    {
        $content = new Content(
            filename: $filename,
            uploadId: '550e8400-e29b-41d4-a716-'.substr(str_pad($fileHashSeed, 12, '0', \STR_PAD_LEFT), -12),
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_pad($fileHashSeed, 64, '0', \STR_PAD_LEFT),
            duration: $duration,
        );
        $this->em->persist($content);
        $this->em->flush();

        return $content;
    }

    public function testWatchEventReturns404ForUnknownContent(): void
    {
        $client = static::getClient();
        $client->request(
            'POST',
            '/api/content/550e8400-e29b-41d4-a716-446655440000/watch-events',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['eventType' => 'play', 'positionSeconds' => 0]),
        );

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testWatchEventReturns422ForInvalidEventType(): void
    {
        $content = $this->createContent('video.mp4', '1');
        $client = static::getClient();
        $client->request(
            'POST',
            "/api/content/{$content->getId()}/watch-events",
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['eventType' => 'bogus', 'positionSeconds' => 0]),
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($body['ok']);
    }

    public function testWatchEventReturns422ForNegativePosition(): void
    {
        $content = $this->createContent('video.mp4', '2');
        $client = static::getClient();
        $client->request(
            'POST',
            "/api/content/{$content->getId()}/watch-events",
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['eventType' => 'play', 'positionSeconds' => -5]),
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testWatchEventCreatesRow(): void
    {
        $content = $this->createContent('video.mp4', '3');
        $client = static::getClient();
        $client->request(
            'POST',
            "/api/content/{$content->getId()}/watch-events",
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['eventType' => 'progress', 'positionSeconds' => 12.5]),
        );

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);

        $events = $this->em->getRepository(WatchEvent::class)->findAll();
        $this->assertCount(1, $events);
        $this->assertSame('progress', $events[0]->getEventType());
        $this->assertSame(12.5, $events[0]->getPositionSeconds());
        // No auth in the test firewall, so viewer identity falls back to 'anonymous'.
        $this->assertSame('anonymous', $events[0]->getViewerId());
    }

    public function testAnalyticsReturnsZeroedStatsWhenNoEvents(): void
    {
        $content = $this->createContent('video.mp4', '4', duration: 100.0);
        $client = static::getClient();
        $client->request('GET', "/api/content/{$content->getId()}/analytics");

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame(0, $body['views']);
        $this->assertEqualsWithDelta(0.0, $body['completionRate'], 0.001);
        $this->assertEqualsWithDelta(0.0, $body['totalWatchTimeSeconds'], 0.001);
        $this->assertNull($body['averageDropOffSeconds']);
        $this->assertSame([], $body['retentionCurve']);
    }

    public function testAnalyticsComputesAggregatesFromEvents(): void
    {
        $content = $this->createContent('video.mp4', '5', duration: 100.0);

        foreach ([10.0, 20.0, 30.0] as $position) {
            $this->em->persist(new WatchEvent($content, 'viewer1@example.com', 'progress', $position));
        }
        $this->em->persist(new WatchEvent($content, 'viewer1@example.com', 'complete', 100.0));
        $this->em->persist(new WatchEvent($content, 'viewer2@example.com', 'progress', 5.0));
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', "/api/content/{$content->getId()}/analytics");

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame(2, $body['views']);
        $this->assertEqualsWithDelta(50.0, $body['completionRate'], 0.001);
        $this->assertEqualsWithDelta(20.0, $body['totalWatchTimeSeconds'], 0.001); // 4 progress events * 5s heartbeat
        $this->assertEqualsWithDelta(10.0, $body['averageWatchTimeSeconds'], 0.001);
        $this->assertEqualsWithDelta(52.5, $body['averageDropOffSeconds'], 0.001); // avg(100, 5)

        $this->assertCount(11, $body['retentionCurve']);
        $this->assertSame(0, $body['retentionCurve'][0]['percent']);
        $this->assertEqualsWithDelta(100.0, $body['retentionCurve'][0]['retentionRate'], 0.001);
        $this->assertSame(10, $body['retentionCurve'][1]['percent']);
        $this->assertEqualsWithDelta(50.0, $body['retentionCurve'][1]['retentionRate'], 0.001);
        $this->assertSame(100, $body['retentionCurve'][10]['percent']);
        $this->assertEqualsWithDelta(50.0, $body['retentionCurve'][10]['retentionRate'], 0.001);
    }

    public function testOverviewAggregatesAcrossVideos(): void
    {
        $watched = $this->createContent('watched.mp4', '6', duration: 100.0);
        $this->createContent('unwatched.mp4', '7', duration: 50.0);

        $this->em->persist(new WatchEvent($watched, 'viewer1@example.com', 'progress', 10.0));
        $this->em->persist(new WatchEvent($watched, 'viewer1@example.com', 'complete', 100.0));
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', '/api/analytics/overview');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertTrue($body['ok']);
        $this->assertSame(2, $body['totalVideos']);
        $this->assertSame(1, $body['totalViews']);
        $this->assertEqualsWithDelta(5.0, $body['totalWatchTimeSeconds'], 0.001); // 1 progress event * 5s
        $this->assertEqualsWithDelta(100.0, $body['averageCompletionRate'], 0.001); // only the one video with views counts
        $this->assertCount(2, $body['videos']);
        $this->assertSame('watched.mp4', $body['videos'][0]['filename']);
        $this->assertSame(1, $body['videos'][0]['views']);
        $this->assertSame('unwatched.mp4', $body['videos'][1]['filename']);
        $this->assertSame(0, $body['videos'][1]['views']);
    }

    public function testProgressReturns404ForUnknownContent(): void
    {
        $client = static::getClient();
        $client->request('GET', '/api/content/550e8400-e29b-41d4-a716-446655440000/progress');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testProgressReturnsNullWhenNoEvents(): void
    {
        $content = $this->createContent('video.mp4', '8', duration: 200.0);
        $client = static::getClient();
        $client->request('GET', "/api/content/{$content->getId()}/progress");

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertNull($body['positionSeconds']);
    }

    public function testProgressReturnsLastPositionWhenInProgress(): void
    {
        $content = $this->createContent('video.mp4', '9', duration: 200.0);
        // No auth in the test firewall, so the viewer identity is 'anonymous'.
        $this->em->persist(new WatchEvent($content, 'anonymous', 'progress', 20.0));
        $this->em->persist(new WatchEvent($content, 'anonymous', 'progress', 45.0));
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', "/api/content/{$content->getId()}/progress");

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertEqualsWithDelta(45.0, $body['positionSeconds'], 0.001);
    }

    public function testProgressReturnsNullWhenLastEventIsComplete(): void
    {
        $content = $this->createContent('video.mp4', '10', duration: 200.0);
        $this->em->persist(new WatchEvent($content, 'anonymous', 'progress', 45.0));
        $this->em->persist(new WatchEvent($content, 'anonymous', 'complete', 200.0));
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', "/api/content/{$content->getId()}/progress");

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertNull($body['positionSeconds']);
    }

    public function testProgressReturnsNullWhenNearStart(): void
    {
        $content = $this->createContent('video.mp4', '11', duration: 200.0);
        $this->em->persist(new WatchEvent($content, 'anonymous', 'progress', 3.0));
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', "/api/content/{$content->getId()}/progress");

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertNull($body['positionSeconds']);
    }

    public function testProgressReturnsNullWhenNearEnd(): void
    {
        $content = $this->createContent('video.mp4', '12', duration: 200.0);
        $this->em->persist(new WatchEvent($content, 'anonymous', 'progress', 198.0));
        $this->em->flush();

        $client = static::getClient();
        $client->request('GET', "/api/content/{$content->getId()}/progress");

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertNull($body['positionSeconds']);
    }
}
