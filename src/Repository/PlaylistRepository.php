<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Playlist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Playlist>
 */
class PlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playlist::class);
    }

    /**
     * @return Playlist[]
     */
    public function findByOwner(string $ownerId): array
    {
        return $this->findBy(['ownerId' => $ownerId], ['createdAt' => 'DESC']);
    }
}
