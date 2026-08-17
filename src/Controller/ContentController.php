<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ContentDto;
use App\Dto\ContentListDto;
use App\Dto\LanguageOptionDto;
use App\Dto\ProgressDto;
use App\Dto\RelatedVideosDto;
use App\Dto\VideoAnalyticsDto;
use App\Entity\WatchEvent;
use App\Repository\ContentRepository;
use App\Security\PermissionChecker;
use App\Service\CaptionLanguages;
use App\Service\CaptionTranslationService;
use App\Service\ThumbnailGenerator;
use App\Service\VideoAnalyticsService;
use App\Service\VideoSummaryService;
use App\Service\VttFormatter;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class ContentController extends AbstractController
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PermissionChecker $permissionChecker,
        private readonly FilesystemOperator $filesystem,
        private readonly VideoSummaryService $summaryService,
        private readonly VideoAnalyticsService $analyticsService,
        private readonly ThumbnailGenerator $thumbnailGenerator,
        private readonly CaptionTranslationService $captionTranslationService,
        #[Autowire(service: 'ai.retriever.video_transcript_embeds')]
        private readonly RetrieverInterface $retriever,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/api/caption-languages', name: 'api_caption_languages', methods: ['GET'])]
    #[OA\Get(
        path: '/api/caption-languages',
        operationId: 'listCaptionLanguages',
        summary: 'The curated set of languages offerable for caption generation, e.g. at upload time',
        tags: ['Content'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Available caption languages',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: LanguageOptionDto::class))),
            ),
        ],
    )]
    public function captionLanguages(): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $languages = array_map(
            static fn (string $code) => new LanguageOptionDto($code, CaptionLanguages::labelFor($code)),
            CaptionLanguages::TRANSLATION_TARGETS,
        );

        return $this->json($languages);
    }

    #[Route('/api/content', name: 'api_content_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/content',
        operationId: 'listContent',
        summary: 'List all content items, paginated (12 per page)',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'Filter to videos with this exact AI-assigned category', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'owner', in: 'query', required: false, description: 'Pass "me" to filter to only the authenticated user\'s own uploads', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated content list',
                content: new OA\JsonContent(ref: new Model(type: ContentListDto::class)),
            ),
        ],
    )]
    public function list(Request $request): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = 12;
        $category = $request->query->get('category');
        $category = null !== $category && '' !== $category ? (string) $category : null;

        $ownerId = 'me' === $request->query->get('owner') ? ($this->getUser()?->getUserIdentifier() ?? 'anonymous') : null;

        ['items' => $items, 'total' => $total] = $this->contentRepository->findPaginated($page, $perPage, $category, $ownerId);

        $totalPages = max(1, (int) ceil($total / $perPage));

        return $this->json(new ContentListDto(
            ok: true,
            items: array_map(ContentDto::fromEntity(...), $items),
            total: $total,
            page: $page,
            perPage: $perPage,
            totalPages: $totalPages,
            hasNext: $page < $totalPages,
            hasPrev: $page > 1,
            availableCategories: $this->contentRepository->findDistinctCategories(),
        ));
    }

    #[Route('/api/content/{id}', name: 'api_content_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/content/{id}',
        operationId: 'getContent',
        summary: 'Retrieve content metadata and transcription status by UUID',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Content found',
                content: new OA\JsonContent(ref: new Model(type: ContentDto::class)),
            ),
            new OA\Response(
                response: 404,
                description: 'Content not found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'ok', type: 'boolean', example: false),
                        new OA\Property(property: 'error', type: 'string'),
                    ],
                ),
            ),
        ],
    )]
    public function get(string $id): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content || null !== $content->getDeletedAt()) {
            return $this->json(
                ['ok' => false, 'error' => 'Content not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return $this->json(ContentDto::fromEntity($content));
    }

    #[Route('/api/content/{id}', name: 'api_content_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/content/{id}',
        operationId: 'deleteContent',
        summary: 'Archive a content item (soft delete — sets deletedAt, data is retained)',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Content archived', content: new OA\JsonContent(ref: new Model(type: ContentDto::class))),
            new OA\Response(response: 404, description: 'Content not found or already archived'),
        ],
    )]
    public function delete(string $id): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:update')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:update permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content || null !== $content->getDeletedAt()) {
            return $this->json(['ok' => false, 'error' => 'Content not found.'], Response::HTTP_NOT_FOUND);
        }

        // Editors may only delete their own uploads; admins can delete anything.
        if (!$this->permissionChecker->isAdmin()) {
            $viewerId = $this->getUser()?->getUserIdentifier() ?? 'anonymous';
            if ($content->getOwnerId() !== $viewerId) {
                return $this->json(['ok' => false, 'error' => 'Forbidden. You can only delete your own videos.'], Response::HTTP_FORBIDDEN);
            }
        }

        $content->archive();
        $this->entityManager->flush();
        $this->summaryService->removeEmbedding((string) $content->getId());

        return $this->json(ContentDto::fromEntity($content));
    }

    #[Route('/api/content/{id}', name: 'api_content_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/content/{id}',
        operationId: 'updateContent',
        summary: 'Update editable fields (currently: title)',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', nullable: true, maxLength: 255),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated content', content: new OA\JsonContent(ref: new Model(type: ContentDto::class))),
            new OA\Response(response: 404, description: 'Content not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(string $id, Request $request): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:update')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:update permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);
        if (null === $content) {
            return $this->json(['ok' => false, 'error' => 'Content not found.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('title', $body)) {
            $title = $body['title'];
            if ($title !== null && (!is_string($title) || strlen($title) === 0 || strlen($title) > 255)) {
                return $this->json(['ok' => false, 'error' => 'title must be a non-empty string up to 255 characters, or null.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $content->setTitle($title);
        }

        if (array_key_exists('summary', $body) && null !== $content->getTranscription()) {
            $summary = $body['summary'];
            if ($summary !== null && (!is_string($summary) || strlen($summary) === 0 || strlen($summary) > 200)) {
                return $this->json(['ok' => false, 'error' => 'summary must be a non-empty string up to 200 characters, or null.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $content->getTranscription()->setSummary($summary);
        }

        $this->entityManager->flush();

        return $this->json(ContentDto::fromEntity($content));
    }

    #[Route('/api/content/{id}/summarize', name: 'api_content_summarize', methods: ['POST'])]
    #[OA\Post(
        path: '/api/content/{id}/summarize',
        operationId: 'summarizeContent',
        summary: 'Generate an AI summary from the transcript and save it (on-demand)',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Summary generated and saved', content: new OA\JsonContent(ref: new Model(type: ContentDto::class))),
            new OA\Response(response: 404, description: 'Content not found'),
            new OA\Response(response: 422, description: 'Transcription not yet completed'),
        ],
    )]
    public function summarize(string $id): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:update')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:update permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content || null !== $content->getDeletedAt()) {
            return $this->json(['ok' => false, 'error' => 'Content not found.'], Response::HTTP_NOT_FOUND);
        }

        $transcription = $content->getTranscription();

        if (null === $transcription || 'completed' !== $transcription->getStatus() || null === $transcription->getTranscription()) {
            return $this->json(['ok' => false, 'error' => 'No completed transcription available.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $summary = $this->summaryService->summarize($transcription->getTranscription());
        $transcription->setSummary($summary);
        $this->entityManager->flush();

        return $this->json(ContentDto::fromEntity($content));
    }

    #[Route('/api/content/{id}/stream', name: 'api_content_stream', methods: ['GET'])]
    #[OA\Get(
        path: '/api/content/{id}/stream',
        operationId: 'streamContent',
        summary: 'Stream the raw MP4 file — supports HTTP Range requests for seeking',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'MP4 file stream'),
            new OA\Response(response: 206, description: 'Partial content (Range request)'),
            new OA\Response(response: 404, description: 'Content or file not found'),
        ],
    )]
    public function streamFile(string $id, Request $request): Response
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content) {
            return $this->json(
                ['ok' => false, 'error' => 'Content not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $filePath = $this->projectDir.'/var/uploads/'.$content->getUploadId().'/'.$content->getFilename();

        if (!is_file($filePath)) {
            return $this->json(
                ['ok' => false, 'error' => 'File not found on disk.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', 'video/mp4');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $content->getFilename());
        $response->prepare($request);

        return $response;
    }

    #[Route('/api/content/{id}/thumbnail', name: 'api_content_thumbnail', methods: ['GET'])]
    #[OA\Get(
        path: '/api/content/{id}/thumbnail',
        operationId: 'getContentThumbnail',
        summary: 'Serve the JPEG thumbnail — publicly accessible, no auth required',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'JPEG thumbnail image'),
            new OA\Response(response: 404, description: 'Content not found or thumbnail not yet generated'),
        ],
    )]
    public function thumbnail(string $id): Response
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }
        
        $content = $this->contentRepository->find($id);

        if ($content === null || !$content->hasThumbnail()) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        try {
            $jpeg = $this->filesystem->read(ThumbnailGenerator::thumbnailPath($content->getUploadId()));
        } catch (\Throwable) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        return new Response($jpeg, Response::HTTP_OK, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400, immutable',
        ]);
    }

    #[Route('/api/content/{id}/thumbnail', name: 'api_content_regenerate_thumbnail', methods: ['POST'])]
    #[OA\Post(
        path: '/api/content/{id}/thumbnail',
        operationId: 'regenerateContentThumbnail',
        summary: 'Regenerate the thumbnail — extracts fresh candidate frames and has a vision model pick the best one',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Thumbnail regenerated', content: new OA\JsonContent(ref: new Model(type: ContentDto::class))),
            new OA\Response(response: 404, description: 'Content not found'),
            new OA\Response(response: 422, description: 'Thumbnail generation failed (no readable video frames)'),
        ],
    )]
    public function regenerateThumbnail(string $id): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:update')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:update permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content || null !== $content->getDeletedAt()) {
            return $this->json(['ok' => false, 'error' => 'Content not found.'], Response::HTTP_NOT_FOUND);
        }

        $candidateCount = $this->thumbnailGenerator->generate($content->getUploadId(), $content->getFilename(), $content->getDuration());

        if (0 === $candidateCount) {
            return $this->json(['ok' => false, 'error' => 'Thumbnail generation failed.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $content->setHasThumbnail(true);
        $content->setThumbnailCandidateCount($candidateCount);
        $this->entityManager->flush();

        return $this->json(ContentDto::fromEntity($content));
    }

    #[Route('/api/content/{id}/thumbnail/candidates/{index}', name: 'api_content_thumbnail_candidate', methods: ['GET'], requirements: ['index' => '\d+'])]
    #[OA\Get(
        path: '/api/content/{id}/thumbnail/candidates/{index}',
        operationId: 'getContentThumbnailCandidate',
        summary: 'Serve a candidate thumbnail frame — publicly accessible, no auth required',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'index', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'JPEG candidate frame'),
            new OA\Response(response: 404, description: 'Content not found or candidate index out of range'),
        ],
    )]
    public function thumbnailCandidate(string $id, int $index): Response
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content || $index < 0 || $index >= $content->getThumbnailCandidateCount()) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        try {
            $jpeg = $this->filesystem->read(ThumbnailGenerator::candidatePath($content->getUploadId(), $index));
        } catch (\Throwable) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        return new Response($jpeg, Response::HTTP_OK, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400, immutable',
        ]);
    }

    #[Route('/api/content/{id}/thumbnail/select', name: 'api_content_thumbnail_select', methods: ['POST'])]
    #[OA\Post(
        path: '/api/content/{id}/thumbnail/select',
        operationId: 'selectContentThumbnail',
        summary: 'Pick one of the generated candidate frames as the thumbnail, overriding the AI auto-pick',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['index'], properties: [
                new OA\Property(property: 'index', type: 'integer'),
            ]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Thumbnail updated', content: new OA\JsonContent(ref: new Model(type: ContentDto::class))),
            new OA\Response(response: 404, description: 'Content not found'),
            new OA\Response(response: 422, description: 'Index out of range'),
        ],
    )]
    public function selectThumbnail(string $id, Request $request): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:update')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:update permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content || null !== $content->getDeletedAt()) {
            return $this->json(['ok' => false, 'error' => 'Content not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $index = (int) ($data['index'] ?? -1);

        if ($index < 0 || $index >= $content->getThumbnailCandidateCount()) {
            return $this->json(['ok' => false, 'error' => 'Invalid candidate index.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->filesystem->write(
            ThumbnailGenerator::thumbnailPath($content->getUploadId()),
            $this->filesystem->read(ThumbnailGenerator::candidatePath($content->getUploadId(), $index)),
        );

        $content->setHasThumbnail(true);
        $this->entityManager->flush();

        return $this->json(ContentDto::fromEntity($content));
    }

    #[Route('/api/content/{id}/watch-events', name: 'api_content_watch_event', methods: ['POST'])]
    #[OA\Post(
        path: '/api/content/{id}/watch-events',
        operationId: 'recordWatchEvent',
        summary: 'Record a playback event (play, pause, seek, progress heartbeat, or completion) for watch analytics — publicly reachable, no auth required',
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['eventType', 'positionSeconds'],
                properties: [
                    new OA\Property(property: 'eventType', type: 'string', enum: WatchEvent::EVENT_TYPES),
                    new OA\Property(property: 'positionSeconds', type: 'number', format: 'float', minimum: 0),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Event recorded',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'ok', type: 'boolean', example: true)]),
            ),
            new OA\Response(response: 404, description: 'Content not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function recordWatchEvent(string $id, Request $request): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content || null !== $content->getDeletedAt()) {
            return $this->json(['ok' => false, 'error' => 'Content not found.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $eventType = (string) ($body['eventType'] ?? '');
        $positionSeconds = $body['positionSeconds'] ?? null;

        if (!in_array($eventType, WatchEvent::EVENT_TYPES, true)) {
            return $this->json(
                ['ok' => false, 'error' => 'eventType must be one of: '.implode(', ', WatchEvent::EVENT_TYPES).'.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!is_numeric($positionSeconds) || (float) $positionSeconds < 0) {
            return $this->json(['ok' => false, 'error' => 'positionSeconds must be a non-negative number.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $viewerId = $this->getUser()?->getUserIdentifier() ?? 'anonymous';

        $event = new WatchEvent($content, $viewerId, $eventType, (float) $positionSeconds);
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $this->json(['ok' => true], Response::HTTP_CREATED);
    }

    #[Route('/api/content/{id}/analytics', name: 'api_content_analytics', methods: ['GET'])]
    #[OA\Get(
        path: '/api/content/{id}/analytics',
        operationId: 'getContentAnalytics',
        summary: 'Get watch analytics (views, completion rate, watch time, retention curve) for a single video',
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Analytics computed', content: new OA\JsonContent(ref: new Model(type: VideoAnalyticsDto::class))),
            new OA\Response(response: 404, description: 'Content not found'),
        ],
    )]
    public function analytics(string $id): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content || null !== $content->getDeletedAt()) {
            return $this->json(['ok' => false, 'error' => 'Content not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->analyticsService->buildVideoAnalytics($content));
    }

    #[Route('/api/content/{id}/progress', name: 'api_content_progress', methods: ['GET'])]
    #[OA\Get(
        path: '/api/content/{id}/progress',
        operationId: 'getContentProgress',
        summary: 'Get the current viewer\'s last playback position, for "resume where you left off" — publicly reachable, no auth required',
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Progress computed', content: new OA\JsonContent(ref: new Model(type: ProgressDto::class))),
            new OA\Response(response: 404, description: 'Content not found'),
        ],
    )]
    public function progress(string $id): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content || null !== $content->getDeletedAt()) {
            return $this->json(['ok' => false, 'error' => 'Content not found.'], Response::HTTP_NOT_FOUND);
        }

        $viewerId = $this->getUser()?->getUserIdentifier() ?? 'anonymous';

        return $this->json(new ProgressDto(
            ok: true,
            positionSeconds: $this->analyticsService->getResumePosition($content, $viewerId),
        ));
    }

    #[Route('/api/content/{id}/captions/{lang}.vtt', name: 'api_content_captions', methods: ['GET'], requirements: ['lang' => '[a-zA-Z]+'])]
    #[OA\Get(
        path: '/api/content/{id}/captions/{lang}.vtt',
        operationId: 'getContentCaptions',
        summary: 'Serve WebVTT captions — {lang} is either the video\'s native language code or a supported translation target, publicly accessible for the native <track> element',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lang', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'WebVTT captions file'),
            new OA\Response(response: 404, description: 'Content/transcription not found, or unsupported language'),
            new OA\Response(response: 502, description: 'Translation failed'),
        ],
    )]
    public function captions(string $id, string $lang): Response
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content || null !== $content->getDeletedAt()) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        $transcription = $content->getTranscription();

        if (null === $transcription || null === $transcription->getSegments() || null === $transcription->getLanguage()) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        $nativeCode = CaptionLanguages::codeForWhisperLanguage($transcription->getLanguage());

        if ($lang === $nativeCode) {
            $vtt = VttFormatter::format($transcription->getSegments());
        } elseif (in_array($lang, CaptionLanguages::TRANSLATION_TARGETS, true)) {
            $segments = $transcription->getTranslation($lang);

            if (null === $segments) {
                try {
                    $segments = $this->captionTranslationService->translate($transcription->getSegments(), $lang);
                } catch (\Throwable) {
                    return new Response(null, Response::HTTP_BAD_GATEWAY);
                }
                $transcription->setTranslation($lang, $segments);
                $this->entityManager->flush();
            }

            $vtt = VttFormatter::format($segments);
        } else {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        return new Response($vtt, Response::HTTP_OK, [
            'Content-Type' => 'text/vtt; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    #[Route('/api/content/{id}/related', name: 'api_content_related', methods: ['GET'])]
    #[OA\Get(
        path: '/api/content/{id}/related',
        operationId: 'getRelatedContent',
        summary: '"More like this" — semantically similar videos via the existing pgvector transcript embeddings, no extra AI cost beyond what upload already spends embedding the transcript',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Related videos, nearest first — empty until this video has a completed, embedded transcript',
                content: new OA\JsonContent(ref: new Model(type: RelatedVideosDto::class)),
            ),
            new OA\Response(response: 404, description: 'Content not found'),
        ],
    )]
    public function related(string $id): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $content = $this->contentRepository->find($id);

        if (null === $content || null !== $content->getDeletedAt()) {
            return $this->json(['ok' => false, 'error' => 'Content not found.'], Response::HTTP_NOT_FOUND);
        }

        $transcription = $content->getTranscription();

        if (null === $transcription || 'completed' !== $transcription->getStatus() || null === $transcription->getTranscription()) {
            return $this->json(new RelatedVideosDto(ok: true, items: []));
        }

        // Ask for one extra — the video's own transcript is itself in the store and
        // will typically rank as its own top match, so it needs filtering out below.
        $matches = $this->retriever->retrieve($transcription->getTranscription(), ['limit' => 6]);

        $related = [];
        foreach ($matches as $doc) {
            if (count($related) >= 5) {
                break;
            }

            if ((string) $doc->getId() === (string) $content->getId()) {
                continue;
            }

            $matchedContent = $this->contentRepository->find((string) $doc->getId());
            if (null === $matchedContent || null !== $matchedContent->getDeletedAt()) {
                continue;
            }

            $related[] = ContentDto::fromEntity($matchedContent);
        }

        return $this->json(new RelatedVideosDto(ok: true, items: $related));
    }
}
