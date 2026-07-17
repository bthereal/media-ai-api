<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\VideoTranscription;
use App\Security\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TranscriptionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PermissionChecker $permissionChecker
    ) {
    }

    #[Route('/api/transcription/{uploadId}', name: 'api_transcription_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/transcription/{uploadId}',
        operationId: 'getTranscription',
        summary: 'Get transcription status and result for an uploaded video',
        tags: ['Transcription'],
        parameters: [
            new OA\Parameter(name: 'uploadId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transcription record found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'ok', type: 'boolean', example: true),
                        new OA\Property(property: 'uploadId', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'filename', type: 'string', example: 'video.mp4'),
                        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'processing', 'completed', 'failed']),
                        new OA\Property(property: 'transcription', type: 'string', nullable: true),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'completedAt', type: 'string', format: 'date-time', nullable: true),
                    ],
                ),
            ),
            new OA\Response(response: 404, description: 'No transcription found for this uploadId'),
        ],
    )]
    public function getTranscription(string $uploadId): JsonResponse
    {
        if (!$this->permissionChecker->hasPermission('content:read')) {
            return $this->json(['ok' => false, 'error' => 'Forbidden. Requires content:read permission.'], Response::HTTP_FORBIDDEN);
        }

        $repo = $this->entityManager->getRepository(VideoTranscription::class);
        $records = $repo->findBy(['uploadId' => $uploadId], ['createdAt' => 'DESC']);

        if ([] === $records) {
            return $this->json(
                ['ok' => false, 'error' => 'No transcription found for this uploadId.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $record = $records[0];

        return $this->json([
            'ok' => true,
            'uploadId' => $record->getUploadId(),
            'filename' => $record->getFilename(),
            'status' => $record->getStatus(),
            'transcription' => $record->getTranscription(),
            'createdAt' => $record->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'completedAt' => $record->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}
