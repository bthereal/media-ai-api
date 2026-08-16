<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Content;
use App\Entity\VideoTranscription;
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

    /**
     * Finds an archived Content matching this hash, most-recently-deleted first —
     * the partial unique index on file_hash only covers active rows, so more than
     * one archived row can share a hash (repeated delete/re-upload cycles).
     */
    public function findArchivedByHash(string $fileHash): ?Content
    {
        return $this->createQueryBuilder('c')
            ->where('c.fileHash = :hash')
            ->andWhere('c.deletedAt IS NOT NULL')
            ->setParameter('hash', $fileHash)
            ->orderBy('c.deletedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
    public function findPaginated(int $page, int $perPage, ?string $category = null): array
    {
        $countQb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.deletedAt IS NULL');

        $itemsQb = $this->createQueryBuilder('c')
            ->where('c.deletedAt IS NULL')
            ->orderBy('c.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (null !== $category) {
            $countQb->join('c.transcription', 't')->andWhere('t.category = :category')->setParameter('category', $category);
            $itemsQb->join('c.transcription', 't')->andWhere('t.category = :category')->setParameter('category', $category);
        }

        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $items = $itemsQb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<string>
     */
    public function findDistinctCategories(): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT t.category AS category')
            ->from(VideoTranscription::class, 't')
            ->where('t.category IS NOT NULL')
            ->orderBy('t.category', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'category');
    }
}
