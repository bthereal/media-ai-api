<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Content;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Content>
 */
class ContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Content::class);
    }

    public function findByHash(string $fileHash): ?Content
    {
        return $this->findOneBy(['fileHash' => $fileHash, 'deletedAt' => null]);
    }

    /** @return Content[] */
    public function findWithoutThumbnail(): array
    {
        return $this->findBy(['hasThumbnail' => false]);
    }

    /**
     * In the future this would use a Doctrine based Paginator class to be more efficient
     * 
     * @return array{items: array<Content>, total: int}
     */
    public function findPaginated(int $page, int $perPage): array
    {
        $total = (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $this->createQueryBuilder('c')
            ->where('c.deletedAt IS NULL')
            ->orderBy('c.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
