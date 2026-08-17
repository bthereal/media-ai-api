<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\RoleCatalog;
use PHPUnit\Framework\TestCase;

class RoleCatalogTest extends TestCase
{
    public function testResolveAdminGrantsFullAccessRoleAndNoExplicitPermissions(): void
    {
        $resolved = RoleCatalog::resolve('admin');

        $this->assertSame(['ROLE_GROUP_ADMIN'], $resolved['roles']);
        $this->assertSame([], $resolved['permissions']);
    }

    public function testResolveEditorGrantsContentAdminRoleAndContentPermissions(): void
    {
        $resolved = RoleCatalog::resolve('editor');

        $this->assertSame(['CONTENT_ADMIN'], $resolved['roles']);
        $this->assertSame(['content:create', 'content:read', 'content:update', 'playlist:manage'], $resolved['permissions']);
    }

    public function testResolveThrowsForUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RoleCatalog::resolve('superuser');
    }

    public function testIsValidReturnsTrueForKnownKeys(): void
    {
        $this->assertTrue(RoleCatalog::isValid('admin'));
        $this->assertTrue(RoleCatalog::isValid('editor'));
    }

    public function testIsValidReturnsFalseForUnknownKey(): void
    {
        $this->assertFalse(RoleCatalog::isValid('superuser'));
        $this->assertFalse(RoleCatalog::isValid(''));
    }

    public function testAllListsBothRolesWithLabels(): void
    {
        $this->assertSame(
            [
                ['key' => 'admin', 'label' => 'Admin'],
                ['key' => 'editor', 'label' => 'Editor'],
            ],
            RoleCatalog::all(),
        );
    }
}
