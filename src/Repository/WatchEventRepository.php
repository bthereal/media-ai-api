<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WatchEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WatchEvent>
 */
class WatchEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatchEvent::class);
    }

    public function countDistinctViewers(Uuid $contentId): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(DISTINCT w.viewerId)')
            ->where('w.content = :contentId')
            ->setParameter('contentId', $contentId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCompletedViewers(Uuid $contentId): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(DISTINCT w.viewerId)')
            ->where('w.content = :contentId')
            ->andWhere('w.eventType = :type')
            ->setParameter('contentId', $contentId)
            ->setParameter('type', 'complete')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, float> viewerId => furthest position reached (seconds)
     */
    public function getViewerMaxPositions(Uuid $contentId): array
    {
        $rows = $this->createQueryBuilder('w')
            ->select('w.viewerId AS viewerId', 'MAX(w.positionSeconds) AS maxPosition')
            ->where('w.content = :contentId')
            ->setParameter('contentId', $contentId)
            ->groupBy('w.viewerId')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['viewerId']] = (float) $row['maxPosition'];
        }

        return $result;
    }

    /**
     * @return array<string, int> viewerId => number of "progress" heartbeat events
     */
    public function getViewerProgressCounts(Uuid $contentId): array
    {
        $rows = $this->createQueryBuilder('w')
            ->select('w.viewerId AS viewerId', 'COUNT(w.id) AS cnt')
            ->where('w.content = :contentId')
            ->andWhere('w.eventType = :type')
            ->setParameter('contentId', $contentId)
            ->setParameter('type', 'progress')
            ->groupBy('w.viewerId')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['viewerId']] = (int) $row['cnt'];
        }

        return $result;
    }

    public function getLastEventForViewer(Uuid $contentId, string $viewerId): ?WatchEvent
    {
        // Ordered by id (UUIDv7, time-ordered to sub-millisecond precision) rather than
        // createdAt (TIMESTAMP(0), second precision) — heartbeats can land in the same second.
        return $this->createQueryBuilder('w')
            ->where('w.content = :contentId')
            ->andWhere('w.viewerId = :viewerId')
            ->setParameter('contentId', $contentId)
            ->setParameter('viewerId', $viewerId)
            ->orderBy('w.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
