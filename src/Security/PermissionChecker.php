<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class PermissionChecker
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    /**
     * Returns true if the current JWT has the given role AND permission,
     * or if the user is a ROLE_GROUP_ADMIN (which bypasses all checks).
     * Returns true when the firewall has no security token (e.g. test env).
     */
    public function hasRoleAndPermission(string $role, string $permission): bool
    {
        if ($this->tokenStorage->getToken() === null) {
            return true;
        }
        if ($this->isGroupAdmin()) {
            return true;
        }

        return in_array($role, $this->tenantContext->getRoles(), true)
            && in_array($permission, $this->tenantContext->getPermissions(), true);
    }

    /**
     * Returns true if the current JWT has the given permission,
     * or if the user is a ROLE_GROUP_ADMIN.
     * Returns true when the firewall has no security token (e.g. test env).
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->tokenStorage->getToken() === null) {
            return true;
        }

        return $this->isGroupAdmin()
            || in_array($permission, $this->tenantContext->getPermissions(), true);
    }

    private function isGroupAdmin(): bool
    {
        return in_array('ROLE_GROUP_ADMIN', $this->tenantContext->getRoles(), true);
    }
}
