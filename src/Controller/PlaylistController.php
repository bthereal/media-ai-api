<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PlaylistDetailDto;
use App\Dto\PlaylistDto;
use App\Dto\PlaylistListDto;
use App\Entity\Playlist;
use App\Entity\PlaylistItem;
use App\Repository\ContentRepository;
use App\Repository\PlaylistRepository;
use App\Security\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/playlists', format: 'json')]
class PlaylistController extends AbstractController
{
    public function __construct(
        private readonly PlaylistRepository $playlistRepository,
        private readonly ContentRepository $contentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('', name: 'api_playlists_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/playlists',
        operationId: 'listPlaylists',
        summary: 'List the current user\'s own playlists',
        tags: ['Playlists'],
        responses: [
            new OA\Response(response: 200, description: 'Playlists', content: new OA\JsonContent(ref: new Model(type: PlaylistListDto::class))),
        ],
    )]
    public function list(): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $playlists = $this->playlistRepository->findByOwner($this->resolveOwnerId());

        return $this->json(new PlaylistListDto(ok: true, items: array_map(PlaylistDto::fromEntity(...), $playlists)));
    }

    #[Route('', name: 'api_playlists_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/playlists',
        operationId: 'createPlaylist',
        summary: 'Create a new playlist owned by the current user',
        tags: ['Playlists'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', maxLength: 255),
                    new OA\Property(property: 'visibility', type: 'string', enum: Playlist::VISIBILITIES, example: 'private'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Playlist created', content: new OA\JsonContent(ref: new Model(type: PlaylistDetailDto::class))),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function create(Request $request): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('playlist:manage')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires playlist:manage permission.'], Response::HTTP_FORBIDDEN);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $title = trim((string) ($body['title'] ?? ''));
        $visibility = (string) ($body['visibility'] ?? 'private');

        if ('' === $title || strlen($title) > 255) {
            return $this->json(['ok' => false, 'error' => 'title must be a non-empty string up to 255 characters.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!in_array($visibility, Playlist::VISIBILITIES, true)) {
            return $this->json(['ok' => false, 'error' => 'visibility must be one of: '.implode(', ', Playlist::VISIBILITIES).'.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $playlist = new Playlist($this->resolveOwnerId(), $title, $visibility);
        $this->entityManager->persist($playlist);
        $this->entityManager->flush();

        return $this->json(PlaylistDetailDto::fromEntity($playlist), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_playlists_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/playlists/{id}',
        operationId: 'getPlaylist',
        summary: 'Get a playlist and its ordered items — owner always allowed, others only if visibility is public',
        tags: ['Playlists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Playlist', content: new OA\JsonContent(ref: new Model(type: PlaylistDetailDto::class))),
            new OA\Response(response: 403, description: 'Forbidden — private playlist, not the owner'),
            new OA\Response(response: 404, description: 'Playlist not found'),
        ],
    )]
    public function get(string $id): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $playlist = $this->playlistRepository->find($id);

        if (null === $playlist) {
            return $this->json(['ok' => false, 'error' => 'Playlist not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$playlist->isPublic() && !$playlist->isOwnedBy($this->resolveOwnerId())) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. This playlist is private.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(PlaylistDetailDto::fromEntity($playlist));
    }

    #[Route('/{id}', name: 'api_playlists_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/playlists/{id}',
        operationId: 'updatePlaylist',
        summary: 'Update a playlist\'s title and/or visibility — owner only',
        tags: ['Playlists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', maxLength: 255),
                    new OA\Property(property: 'visibility', type: 'string', enum: Playlist::VISIBILITIES),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated playlist', content: new OA\JsonContent(ref: new Model(type: PlaylistDetailDto::class))),
            new OA\Response(response: 403, description: 'Forbidden — not the owner'),
            new OA\Response(response: 404, description: 'Playlist not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(string $id, Request $request): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('playlist:manage')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires playlist:manage permission.'], Response::HTTP_FORBIDDEN);
        }

        $playlist = $this->playlistRepository->find($id);

        if (null === $playlist) {
            return $this->json(['ok' => false, 'error' => 'Playlist not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$playlist->isOwnedBy($this->resolveOwnerId())) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. You do not own this playlist.'], Response::HTTP_FORBIDDEN);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('title', $body)) {
            $title = trim((string) $body['title']);
            if ('' === $title || strlen($title) > 255) {
                return $this->json(['ok' => false, 'error' => 'title must be a non-empty string up to 255 characters.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $playlist->setTitle($title);
        }

        if (array_key_exists('visibility', $body)) {
            $visibility = (string) $body['visibility'];
            if (!in_array($visibility, Playlist::VISIBILITIES, true)) {
                return $this->json(['ok' => false, 'error' => 'visibility must be one of: '.implode(', ', Playlist::VISIBILITIES).'.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $playlist->setVisibility($visibility);
        }

        $this->entityManager->flush();

        return $this->json(PlaylistDetailDto::fromEntity($playlist));
    }

    #[Route('/{id}', name: 'api_playlists_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/playlists/{id}',
        operationId: 'deletePlaylist',
        summary: 'Delete a playlist — owner only',
        tags: ['Playlists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Playlist deleted'),
            new OA\Response(response: 403, description: 'Forbidden — not the owner'),
            new OA\Response(response: 404, description: 'Playlist not found'),
        ],
    )]
    public function delete(string $id): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('playlist:manage')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires playlist:manage permission.'], Response::HTTP_FORBIDDEN);
        }

        $playlist = $this->playlistRepository->find($id);

        if (null === $playlist) {
            return $this->json(['ok' => false, 'error' => 'Playlist not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$playlist->isOwnedBy($this->resolveOwnerId())) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. You do not own this playlist.'], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($playlist);
        $this->entityManager->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}/items', name: 'api_playlists_add_item', methods: ['POST'])]
    #[OA\Post(
        path: '/api/playlists/{id}/items',
        operationId: 'addPlaylistItem',
        summary: 'Append a video to the end of a playlist — owner only',
        tags: ['Playlists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['contentId'], properties: [
                new OA\Property(property: 'contentId', type: 'string', format: 'uuid'),
            ]),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Item added', content: new OA\JsonContent(ref: new Model(type: PlaylistDetailDto::class))),
            new OA\Response(response: 403, description: 'Forbidden — not the owner'),
            new OA\Response(response: 404, description: 'Playlist or content not found'),
            new OA\Response(response: 422, description: 'Content is already in this playlist'),
        ],
    )]
    public function addItem(string $id, Request $request): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('playlist:manage')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires playlist:manage permission.'], Response::HTTP_FORBIDDEN);
        }

        $playlist = $this->playlistRepository->find($id);

        if (null === $playlist) {
            return $this->json(['ok' => false, 'error' => 'Playlist not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$playlist->isOwnedBy($this->resolveOwnerId())) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. You do not own this playlist.'], Response::HTTP_FORBIDDEN);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $contentId = (string) ($body['contentId'] ?? '');

        $content = $this->contentRepository->find($contentId);
        if (null === $content || null !== $content->getDeletedAt()) {
            return $this->json(['ok' => false, 'error' => 'Content not found.'], Response::HTTP_NOT_FOUND);
        }

        foreach ($playlist->getItems() as $existing) {
            if ((string) $existing->getContent()->getId() === (string) $content->getId()) {
                return $this->json(['ok' => false, 'error' => 'This video is already in the playlist.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $item = new PlaylistItem($playlist, $content, $playlist->nextPosition());
        $playlist->addItem($item);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $this->json(PlaylistDetailDto::fromEntity($playlist), Response::HTTP_CREATED);
    }

    #[Route('/{id}/items/{itemId}', name: 'api_playlists_remove_item', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/playlists/{id}/items/{itemId}',
        operationId: 'removePlaylistItem',
        summary: 'Remove a video from a playlist and compact remaining positions — owner only',
        tags: ['Playlists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item removed', content: new OA\JsonContent(ref: new Model(type: PlaylistDetailDto::class))),
            new OA\Response(response: 403, description: 'Forbidden — not the owner'),
            new OA\Response(response: 404, description: 'Playlist or item not found'),
        ],
    )]
    public function removeItem(string $id, string $itemId): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('playlist:manage')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires playlist:manage permission.'], Response::HTTP_FORBIDDEN);
        }

        $playlist = $this->playlistRepository->find($id);

        if (null === $playlist) {
            return $this->json(['ok' => false, 'error' => 'Playlist not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$playlist->isOwnedBy($this->resolveOwnerId())) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. You do not own this playlist.'], Response::HTTP_FORBIDDEN);
        }

        $target = null;
        foreach ($playlist->getItems() as $item) {
            if ((string) $item->getId() === $itemId) {
                $target = $item;
                break;
            }
        }

        if (null === $target) {
            return $this->json(['ok' => false, 'error' => 'Playlist item not found.'], Response::HTTP_NOT_FOUND);
        }

        $playlist->removeItem($target);
        $this->entityManager->remove($target);
        $this->entityManager->flush();

        $this->compactPositions($playlist);
        $this->entityManager->flush();

        return $this->json(PlaylistDetailDto::fromEntity($playlist));
    }

    #[Route('/{id}/items', name: 'api_playlists_reorder_items', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/playlists/{id}/items',
        operationId: 'reorderPlaylistItems',
        summary: 'Reorder playlist items — owner only. Body must list every existing item ID exactly once, in the desired order',
        tags: ['Playlists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['itemIds'], properties: [
                new OA\Property(property: 'itemIds', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
            ]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reordered playlist', content: new OA\JsonContent(ref: new Model(type: PlaylistDetailDto::class))),
            new OA\Response(response: 403, description: 'Forbidden — not the owner'),
            new OA\Response(response: 404, description: 'Playlist not found'),
            new OA\Response(response: 422, description: 'itemIds does not exactly match the playlist\'s current items'),
        ],
    )]
    public function reorderItems(string $id, Request $request): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('playlist:manage')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires playlist:manage permission.'], Response::HTTP_FORBIDDEN);
        }

        $playlist = $this->playlistRepository->find($id);

        if (null === $playlist) {
            return $this->json(['ok' => false, 'error' => 'Playlist not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$playlist->isOwnedBy($this->resolveOwnerId())) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. You do not own this playlist.'], Response::HTTP_FORBIDDEN);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $itemIds = $body['itemIds'] ?? null;

        if (!is_array($itemIds)) {
            return $this->json(['ok' => false, 'error' => 'itemIds must be an array.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $itemsById = [];
        foreach ($playlist->getItems() as $item) {
            $itemsById[(string) $item->getId()] = $item;
        }

        $providedIds = array_map('strval', $itemIds);
        sort($providedIds);
        $existingIds = array_keys($itemsById);
        sort($existingIds);

        if ($providedIds !== $existingIds) {
            return $this->json(['ok' => false, 'error' => 'itemIds must contain exactly the playlist\'s current item IDs, each once.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach (array_values($itemIds) as $position => $itemId) {
            $itemsById[(string) $itemId]->setPosition($position);
        }

        $this->entityManager->flush();

        return $this->json(PlaylistDetailDto::fromEntity($playlist));
    }

    private function compactPositions(Playlist $playlist): void
    {
        $position = 0;
        foreach ($playlist->getItems() as $item) {
            $item->setPosition($position);
            ++$position;
        }
    }

    private function resolveOwnerId(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? 'anonymous';
    }
}
