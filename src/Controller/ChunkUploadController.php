<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Content;
use App\Entity\VideoTranscription;
use App\Exception\StorageException;
use App\Exception\ValidationException;
use App\Message\EmbedVideoSummaryMessage;
use App\Message\TranscribeVideoMessage;
use App\Repository\ContentRepository;
use App\Security\PermissionChecker;
use App\Service\CaptionLanguages;
use App\Service\ChunkUploadService;
use App\Service\ThumbnailGenerator;
use App\Service\VideoMetadataExtractor;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class ChunkUploadController extends AbstractController
{
    public function __construct(
        private readonly ChunkUploadService $chunkUploadService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly ContentRepository $contentRepository,
        private readonly FilesystemOperator $filesystem,
        private readonly VideoMetadataExtractor $metadataExtractor,
        private readonly ThumbnailGenerator $thumbnailGenerator,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('/api/upload/chunk', name: 'api_upload_chunk', methods: ['POST'])]
    #[OA\Post(
        path: '/api/upload/chunk',
        operationId: 'uploadChunk',
        summary: 'Upload a single MP4 chunk (custom sequential chunked upload protocol)',
        tags: ['Upload'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['uploadId', 'chunkIndex', 'totalChunks', 'filename', 'mimeType', 'chunk'],
                    properties: [
                        new OA\Property(property: 'uploadId', type: 'string', format: 'uuid', description: 'UUID identifying the upload session'),
                        new OA\Property(property: 'chunkIndex', type: 'integer', minimum: 0, description: '0-based index of this chunk'),
                        new OA\Property(property: 'totalChunks', type: 'integer', minimum: 1, description: 'Total number of chunks in the upload'),
                        new OA\Property(property: 'filename', type: 'string', example: 'my-video.mp4', description: 'Original filename, must end with .mp4'),
                        new OA\Property(property: 'mimeType', type: 'string', example: 'video/mp4', description: 'MIME type — must be video/mp4'),
                        new OA\Property(property: 'chunk', type: 'string', format: 'binary', description: 'Binary chunk data (max 10MB, typical 2MB)'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Chunk accepted. When this is the final chunk, the file is assembled and transcription is queued.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'ok', type: 'boolean', example: true),
                        new OA\Property(property: 'uploadId', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'chunkIndex', type: 'integer', example: 0),
                        new OA\Property(property: 'contentId', type: 'string', format: 'uuid', description: 'Set when the final chunk triggers assembly'),
                        new OA\Property(property: 'duplicate', type: 'boolean', description: 'True when an identical file was previously uploaded'),
                    ],
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Bad request — missing or invalid parameters',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'ok', type: 'boolean', example: false),
                        new OA\Property(property: 'error', type: 'string'),
                    ],
                ),
            ),
            new OA\Response(response: 415, description: 'Unsupported media type — must be video/mp4'),
            new OA\Response(response: 422, description: 'Invalid chunk — too large or PHP upload error'),
            new OA\Response(response: 500, description: 'Storage error'),
        ],
    )]
    public function uploadChunk(Request $request): JsonResponse
    {
        if (!$this->permissionChecker->hasRoleAndPermission('CONTENT_ADMIN', 'content:create')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires CONTENT_ADMIN role with content:create permission.'], Response::HTTP_FORBIDDEN);
        }

        $uploadId = (string) $request->request->get('uploadId', '');
        $chunkIndex = (int) $request->request->get('chunkIndex', -1);
        $totalChunks = (int) $request->request->get('totalChunks', 0);
        $filename = urldecode((string) $request->request->get('filename', ''));
        $chunk = $request->files->get('chunk');

        if (!$chunk instanceof UploadedFile) {
            return $this->json(['ok' => false, 'error' => 'Missing chunk file.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->chunkUploadService->validateChunk($uploadId, $chunkIndex, $totalChunks, $filename, $chunk);
            $this->chunkUploadService->storeChunk($uploadId, $chunkIndex, $chunk);
            $assembled = $this->chunkUploadService->assembleIfComplete($uploadId, $chunkIndex, $totalChunks, $filename);
        } catch (ValidationException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], $e->getHttpStatus());
        } catch (StorageException) {
            return $this->json(['ok' => false, 'error' => 'Storage error.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($assembled) {
            $hashStream = $this->filesystem->readStream("{$uploadId}/{$filename}");
            $hashCtx = hash_init('sha256');
            hash_update_stream($hashCtx, $hashStream);
            $fileHash = hash_final($hashCtx);
            fclose($hashStream);

            $existing = $this->contentRepository->findByHash($fileHash);
            if (null !== $existing) {
                return $this->json([
                    'ok' => true,
                    'uploadId' => $uploadId,
                    'chunkIndex' => $chunkIndex,
                    'contentId' => (string) $existing->getId(),
                    'duplicate' => true,
                ]);
            }

            // Reactivate a previously-deleted upload of the same file rather than
            // creating a duplicate Content row (and a duplicate search embedding) —
            // its transcription/chapters/tags are still valid since the file is
            // byte-identical, only the search embedding (removed on archive) needs restoring.
            $archived = $this->contentRepository->findArchivedByHash($fileHash);
            if (null !== $archived) {
                $archived->unarchive();
                $this->entityManager->flush();

                $archivedTranscription = $archived->getTranscription();
                if (null !== $archivedTranscription && 'completed' === $archivedTranscription->getStatus() && null !== $archivedTranscription->getTranscription()) {
                    $this->messageBus->dispatch(new EmbedVideoSummaryMessage((string) $archived->getId()));
                }

                return $this->json([
                    'ok' => true,
                    'uploadId' => $uploadId,
                    'chunkIndex' => $chunkIndex,
                    'contentId' => (string) $archived->getId(),
                    'duplicate' => true,
                ]);
            }

            $fileSize = $this->filesystem->fileSize("{$uploadId}/{$filename}");
            $duration = $this->extractDuration($uploadId, $filename);

            $transcription = new VideoTranscription($uploadId, $filename);
            $title = trim(urldecode((string) $request->request->get('title', '')));

            $requestedLanguages = json_decode((string) $request->request->get('captionLanguages', '[]'), true);
            $requestedLanguages = array_values(array_intersect(
                array_filter((array) $requestedLanguages, 'is_string'),
                CaptionLanguages::TRANSLATION_TARGETS,
            ));
            $transcription->setRequestedCaptionLanguages($requestedLanguages);

            $content = new Content(
                filename: $filename,
                uploadId: $uploadId,
                mimeType: 'video/mp4',
                fileSize: $fileSize,
                fileHash: $fileHash,
                duration: $duration,
                ownerId: $this->getUser()?->getUserIdentifier() ?? 'anonymous',
            );
            if ('' !== $title) {
                $content->setTitle($title);
            }
            $content->setTranscription($transcription);

            $this->entityManager->persist($content);
            $this->entityManager->flush();

            $candidateCount = $this->thumbnailGenerator->generate($uploadId, $filename, $duration);
            if ($candidateCount > 0) {
                $content->setHasThumbnail(true);
                $content->setThumbnailCandidateCount($candidateCount);
                $this->entityManager->flush();
            }

            $this->messageBus->dispatch(new TranscribeVideoMessage($uploadId, $filename));

            return $this->json([
                'ok' => true,
                'uploadId' => $uploadId,
                'chunkIndex' => $chunkIndex,
                'contentId' => (string) $content->getId(),
                'duplicate' => false,
            ]);
        }

        return $this->json(['ok' => true, 'uploadId' => $uploadId, 'chunkIndex' => $chunkIndex]);
    }

    private function extractDuration(string $uploadId, string $filename): ?float
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'meta_');
        try {
            $src = $this->filesystem->readStream("{$uploadId}/{$filename}");
            $dest = fopen($tmpFile, 'wb');
            stream_copy_to_stream($src, $dest);
            fclose($src);
            fclose($dest);

            return $this->metadataExtractor->extractDuration($tmpFile);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}
