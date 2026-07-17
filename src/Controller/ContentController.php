<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ContentDto;
use App\Dto\ContentListDto;
use App\Repository\ContentRepository;
use App\Security\PermissionChecker;
use App\Service\ThumbnailGenerator;
use App\Service\VideoSummaryService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
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
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/api/content', name: 'api_content_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/content',
        operationId: 'listContent',
        summary: 'List all content items, paginated (12 per page)',
        tags: ['Content'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
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

        ['items' => $items, 'total' => $total] = $this->contentRepository->findPaginated($page, $perPage);

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

        $content->archive();
        $this->entityManager->flush();

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
}
