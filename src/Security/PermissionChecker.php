<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class PermissionChecker
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

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

    /**
     * Returns true if the current JWT carries ROLE_GROUP_ADMIN, or if there is no
     * security token at all (e.g. test env) — the same bypass convention used by
     * hasPermission()/hasRoleAndPermission(). Used where a caller needs to know
     * specifically whether the requester is an admin (e.g. bypassing per-owner
     * restrictions), not just whether a given permission is present.
     */
    public function isAdmin(): bool
    {
        return $this->tokenStorage->getToken() === null || $this->isGroupAdmin();
    }

    private function isGroupAdmin(): bool
    {
        return in_array('ROLE_GROUP_ADMIN', $this->tenantContext->getRoles(), true);
    }
}
