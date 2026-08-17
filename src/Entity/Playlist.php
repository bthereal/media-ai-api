<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlaylistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
#[ORM\Table(name: 'playlist')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_playlist_owner_id')]
class Playlist
{
    public const array VISIBILITIES = ['private', 'public'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $ownerId;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $visibility;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, PlaylistItem>
     */
    #[ORM\OneToMany(targetEntity: PlaylistItem::class, mappedBy: 'playlist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    public function __construct(string $ownerId, string $title, string $visibility = 'private')
    {
        $this->ownerId = $ownerId;
        $this->title = $title;
        $this->visibility = $visibility;
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): void
    {
        $this->visibility = $visibility;
    }

    public function isPublic(): bool
    {
        return 'public' === $this->visibility;
    }

    public function isOwnedBy(string $ownerId): bool
    {
        return $this->ownerId === $ownerId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, PlaylistItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function nextPosition(): int
    {
        $max = -1;
        foreach ($this->items as $item) {
            $max = max($max, $item->getPosition());
        }

        return $max + 1;
    }

    public function addItem(PlaylistItem $item): void
    {
        $this->items->add($item);
    }

    public function removeItem(PlaylistItem $item): void
    {
        $this->items->removeElement($item);
    }
}
