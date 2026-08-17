<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\User;
use OpenApi\Attributes as OA;

#[OA\Schema(
    required: ['id', 'email', 'firstName', 'lastName', 'roles', 'permissions', 'createdAt'],
)]
final readonly class UserDto
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        #[OA\Property(type: 'string', format: 'uuid')]
        public string $id,
        #[OA\Property(type: 'string', format: 'email')]
        public string $email,
        #[OA\Property(type: 'string')]
        public string $firstName,
        #[OA\Property(type: 'string')]
        public string $lastName,
        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'))]
        public array $roles,
        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'))]
        public array $permissions,
        #[OA\Property(type: 'string', format: 'date-time', nullable: true, description: 'Set when the user has been deactivated; null if active')]
        public ?string $deactivatedAt,
        #[OA\Property(type: 'string', format: 'date-time')]
        public string $createdAt,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        return new self(
            id: (string) $user->getId(),
            email: $user->getEmail(),
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
            roles: $user->getTenantRoles(),
            permissions: $user->getPermissions(),
            deactivatedAt: $user->getDeactivatedAt()?->format(\DateTimeInterface::ATOM),
            createdAt: $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
