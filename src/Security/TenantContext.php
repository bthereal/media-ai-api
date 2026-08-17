<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds tenant claims extracted from the JWT for the current request.
 * Populated by JWTAuthenticatedListener; consumed by PermissionChecker.
 */
class TenantContext implements ResetInterface
{
    /** @var list<string> */
    private array $roles = [];

    /** @var list<string> */
    private array $permissions = [];

    #[\Override]
    public function reset(): void
    {
        $this->roles = [];
        $this->permissions = [];
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    /** @return list<string> */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /** @param list<string> $permissions */
    public function setPermissions(array $permissions): void
    {
        $this->permissions = $permissions;
    }
}
