<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\User;
use App\EventListener\JWTAuthenticatedListener;
use App\Security\TenantContext;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class JWTAuthenticatedListenerTest extends TestCase
{
    private function makeUser(): User
    {
        $user = new User();
        $user->setEmail('user@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setPassword('hash');
        $user->setTenantRoles(['CONTENT_ADMIN']);
        $user->setPermissions(['content:read']);

        return $user;
    }

    public function testThrowsForDeactivatedUser(): void
    {
        $user = $this->makeUser();
        $user->deactivate();

        $token = new UsernamePasswordToken($user, 'api', $user->getRoles());
        $event = new JWTAuthenticatedEvent(['tenant_roles' => ['CONTENT_ADMIN'], 'tenant_permissions' => ['content:read']], $token);

        $listener = new JWTAuthenticatedListener(new TenantContext());

        $this->expectException(CustomUserMessageAuthenticationException::class);

        $listener($event);
    }

    public function testPopulatesTenantContextForActiveUser(): void
    {
        $user = $this->makeUser();

        $token = new UsernamePasswordToken($user, 'api', $user->getRoles());
        $event = new JWTAuthenticatedEvent(['tenant_roles' => ['CONTENT_ADMIN'], 'tenant_permissions' => ['content:read']], $token);

        $tenantContext = new TenantContext();
        $listener = new JWTAuthenticatedListener($tenantContext);

        $listener($event);

        $this->assertSame(['CONTENT_ADMIN'], $tenantContext->getRoles());
        $this->assertSame(['content:read'], $tenantContext->getPermissions());
    }
}
