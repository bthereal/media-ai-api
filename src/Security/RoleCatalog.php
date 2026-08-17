<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Canonical, fixed set of selectable roles for user creation — maps a UI-level
 * role key to the underlying tenantRoles/permissions arrays actually stored on
 * User and checked by PermissionChecker. Keeps AuthController::register() from
 * accepting arbitrary, unvalidated role/permission strings.
 */
final class RoleCatalog
{
    /**
     * @var array<string, array{label: string, roles: list<string>, permissions: list<string>}>
     */
    private const array ROLES = [
        'admin' => [
            'label' => 'Admin',
            'roles' => ['ROLE_GROUP_ADMIN'],
            'permissions' => [],
        ],
        'editor' => [
            'label' => 'Editor',
            'roles' => ['CONTENT_ADMIN'],
            'permissions' => ['content:create', 'content:read', 'content:update', 'playlist:manage'],
        ],
    ];

    public static function isValid(string $key): bool
    {
        return isset(self::ROLES[$key]);
    }

    /**
     * @return array{roles: list<string>, permissions: list<string>}
     */
    public static function resolve(string $key): array
    {
        if (!self::isValid($key)) {
            throw new \InvalidArgumentException(sprintf('Unknown role key "%s".', $key));
        }

        return [
            'roles' => self::ROLES[$key]['roles'],
            'permissions' => self::ROLES[$key]['permissions'],
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function all(): array
    {
        $result = [];
        foreach (self::ROLES as $key => $definition) {
            $result[] = ['key' => $key, 'label' => $definition['label']];
        }

        return $result;
    }
}
