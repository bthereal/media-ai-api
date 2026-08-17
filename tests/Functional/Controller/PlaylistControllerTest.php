<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Content;
use App\Entity\Playlist;
use App\Entity\PlaylistItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlaylistControllerTest extends WebTestCase
{
    private const ENDPOINT = '/api/playlists';

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
        $conn->executeStatement('TRUNCATE TABLE playlist_item, playlist, content, video_transcription RESTART IDENTITY CASCADE');
    }

    private function makeContent(string $hashSeed, string $filename = 'video.mp4'): Content
    {
        $content = new Content(
            filename: $filename,
            uploadId: '550e8400-e29b-41d4-a716-' . substr(str_pad($hashSeed, 12, '0', \STR_PAD_LEFT), -12),
            mimeType: 'video/mp4',
            fileSize: 1024,
            fileHash: str_pad($hashSeed, 64, '0', \STR_PAD_LEFT),
        );
        $this->em->persist($content);
        $this->em->flush();

        return $content;
    }

    private function jsonRequest(string $method, string $uri, array $body = []): void
    {
        static::getClient()->request(
            $method,
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            [] === $body ? null : json_encode($body),
        );
    }

    public function testCreateReturns201AndPersists(): void
    {
        $this->jsonRequest('POST', self::ENDPOINT, ['title' => 'My Playlist']);

        $this->assertSame(201, static::getClient()->getResponse()->getStatusCode());
        $body = json_decode(static::getClient()->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame('My Playlist', $body['title']);
        $this->assertSame('private', $body['visibility']);
        $this->assertSame([], $body['items']);
    }

    public function testCreateRejectsEmptyTitle(): void
    {
        $this->jsonRequest('POST', self::ENDPOINT, ['title' => '   ']);

        $this->assertSame(422, static::getClient()->getResponse()->getStatusCode());
    }

    public function testCreateRejectsInvalidVisibility(): void
    {
        $this->jsonRequest('POST', self::ENDPOINT, ['title' => 'X', 'visibility' => 'bogus']);

        $this->assertSame(422, static::getClient()->getResponse()->getStatusCode());
    }

    public function testListReturnsOnlyOwnPlaylists(): void
    {
        $mine = new Playlist('anonymous', 'Mine');
        $theirs = new Playlist('someone-else@example.com', 'Theirs');
        $this->em->persist($mine);
        $this->em->persist($theirs);
        $this->em->flush();

        static::getClient()->request('GET', self::ENDPOINT);

        $body = json_decode(static::getClient()->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertCount(1, $body['items']);
        $this->assertSame('Mine', $body['items'][0]['title']);
    }

    public function testGetReturns404ForUnknownId(): void
    {
        static::getClient()->request('GET', self::ENDPOINT . '/550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame(404, static::getClient()->getResponse()->getStatusCode());
    }

    public function testGetReturns403ForPrivatePlaylistNotOwned(): void
    {
        $playlist = new Playlist('someone-else@example.com', 'Theirs', 'private');
        $this->em->persist($playlist);
        $this->em->flush();

        static::getClient()->request('GET', self::ENDPOINT . '/' . (string) $playlist->getId());

        $this->assertSame(403, static::getClient()->getResponse()->getStatusCode());
    }

    public function testGetAllowsPublicPlaylistFromNonOwner(): void
    {
        $playlist = new Playlist('someone-else@example.com', 'Theirs', 'public');
        $this->em->persist($playlist);
        $this->em->flush();

        static::getClient()->request('GET', self::ENDPOINT . '/' . (string) $playlist->getId());

        $this->assertSame(200, static::getClient()->getResponse()->getStatusCode());
    }

    public function testUpdateReturns403WhenNotOwner(): void
    {
        $playlist = new Playlist('someone-else@example.com', 'Theirs');
        $this->em->persist($playlist);
        $this->em->flush();

        $this->jsonRequest('PATCH', self::ENDPOINT . '/' . (string) $playlist->getId(), ['title' => 'Hijacked']);

        $this->assertSame(403, static::getClient()->getResponse()->getStatusCode());
    }

    public function testUpdateChangesTitleAndVisibility(): void
    {
        $playlist = new Playlist('anonymous', 'Original');
        $this->em->persist($playlist);
        $this->em->flush();

        $this->jsonRequest('PATCH', self::ENDPOINT . '/' . (string) $playlist->getId(), ['title' => 'Renamed', 'visibility' => 'public']);

        $body = json_decode(static::getClient()->getResponse()->getContent(), true);
        $this->assertSame(200, static::getClient()->getResponse()->getStatusCode());
        $this->assertSame('Renamed', $body['title']);
        $this->assertSame('public', $body['visibility']);
    }

    public function testDeleteReturns403WhenNotOwner(): void
    {
        $playlist = new Playlist('someone-else@example.com', 'Theirs');
        $this->em->persist($playlist);
        $this->em->flush();

        static::getClient()->request('DELETE', self::ENDPOINT . '/' . (string) $playlist->getId());

        $this->assertSame(403, static::getClient()->getResponse()->getStatusCode());
    }

    public function testDeleteRemovesPlaylist(): void
    {
        $playlist = new Playlist('anonymous', 'Mine');
        $this->em->persist($playlist);
        $this->em->flush();
        $id = (string) $playlist->getId();

        static::getClient()->request('DELETE', self::ENDPOINT . '/' . $id);
        $this->assertSame(200, static::getClient()->getResponse()->getStatusCode());

        static::getClient()->request('GET', self::ENDPOINT . '/' . $id);
        $this->assertSame(404, static::getClient()->getResponse()->getStatusCode());
    }

    public function testAddItemAppendsAtNextPosition(): void
    {
        $playlist = new Playlist('anonymous', 'Mine');
        $this->em->persist($playlist);
        $this->em->flush();

        $first = $this->makeContent('1');
        $second = $this->makeContent('2');

        $this->jsonRequest('POST', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items', ['contentId' => (string) $first->getId()]);
        $this->assertSame(201, static::getClient()->getResponse()->getStatusCode());

        $this->jsonRequest('POST', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items', ['contentId' => (string) $second->getId()]);
        $body = json_decode(static::getClient()->getResponse()->getContent(), true);

        $this->assertCount(2, $body['items']);
        $this->assertSame(0, $body['items'][0]['position']);
        $this->assertSame(1, $body['items'][1]['position']);
        $this->assertSame((string) $second->getId(), $body['items'][1]['content']['id']);
    }

    public function testAddItemReturns404ForUnknownContent(): void
    {
        $playlist = new Playlist('anonymous', 'Mine');
        $this->em->persist($playlist);
        $this->em->flush();

        $this->jsonRequest('POST', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items', ['contentId' => '550e8400-e29b-41d4-a716-446655440000']);

        $this->assertSame(404, static::getClient()->getResponse()->getStatusCode());
    }

    public function testAddItemRejectsDuplicate(): void
    {
        $playlist = new Playlist('anonymous', 'Mine');
        $content = $this->makeContent('1');
        $this->em->persist($playlist);
        $this->em->flush();

        $this->jsonRequest('POST', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items', ['contentId' => (string) $content->getId()]);
        $this->assertSame(201, static::getClient()->getResponse()->getStatusCode());

        $this->jsonRequest('POST', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items', ['contentId' => (string) $content->getId()]);
        $this->assertSame(422, static::getClient()->getResponse()->getStatusCode());
    }

    public function testRemoveItemCompactsRemainingPositions(): void
    {
        $playlist = new Playlist('anonymous', 'Mine');
        $this->em->persist($playlist);
        $this->em->flush();

        $contents = [$this->makeContent('1'), $this->makeContent('2'), $this->makeContent('3')];
        $itemIds = [];
        foreach ($contents as $content) {
            $this->jsonRequest('POST', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items', ['contentId' => (string) $content->getId()]);
            $body = json_decode(static::getClient()->getResponse()->getContent(), true);
            $itemIds[] = end($body['items'])['id'];
        }

        // Remove the middle item
        static::getClient()->request('DELETE', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items/' . $itemIds[1]);
        $body = json_decode(static::getClient()->getResponse()->getContent(), true);

        $this->assertSame(200, static::getClient()->getResponse()->getStatusCode());
        $this->assertCount(2, $body['items']);
        $this->assertSame(0, $body['items'][0]['position']);
        $this->assertSame(1, $body['items'][1]['position']);
        $this->assertSame($itemIds[0], $body['items'][0]['id']);
        $this->assertSame($itemIds[2], $body['items'][1]['id']);
    }

    public function testRemoveItemReturns404ForUnknownItem(): void
    {
        $playlist = new Playlist('anonymous', 'Mine');
        $this->em->persist($playlist);
        $this->em->flush();

        static::getClient()->request('DELETE', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items/550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame(404, static::getClient()->getResponse()->getStatusCode());
    }

    public function testReorderItemsAppliesNewPositionsAndReturnsThemInOrder(): void
    {
        $playlist = new Playlist('anonymous', 'Mine');
        $this->em->persist($playlist);
        $this->em->flush();

        $itemIds = [];
        foreach ([$this->makeContent('1'), $this->makeContent('2'), $this->makeContent('3')] as $content) {
            $this->jsonRequest('POST', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items', ['contentId' => (string) $content->getId()]);
            $body = json_decode(static::getClient()->getResponse()->getContent(), true);
            $itemIds[] = end($body['items'])['id'];
        }

        $reversed = array_reverse($itemIds);
        $this->jsonRequest('PATCH', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items', ['itemIds' => $reversed]);

        $this->assertSame(200, static::getClient()->getResponse()->getStatusCode());
        $body = json_decode(static::getClient()->getResponse()->getContent(), true);
        $this->assertSame($reversed, array_column($body['items'], 'id'));
        $this->assertSame([0, 1, 2], array_column($body['items'], 'position'));
    }

    public function testReorderItemsRejectsMismatchedIdSet(): void
    {
        $playlist = new Playlist('anonymous', 'Mine');
        $content = $this->makeContent('1');
        $this->em->persist($playlist);
        $this->em->flush();
        $this->jsonRequest('POST', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items', ['contentId' => (string) $content->getId()]);

        $this->jsonRequest('PATCH', self::ENDPOINT . '/' . (string) $playlist->getId() . '/items', ['itemIds' => ['550e8400-e29b-41d4-a716-446655440000']]);

        $this->assertSame(422, static::getClient()->getResponse()->getStatusCode());
    }
}
