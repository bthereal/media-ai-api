<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\TenantContext;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Reads tenant_roles and tenant_permissions from the validated JWT
 * and populates TenantContext so controllers can check permissions.
 */
#[AsEventListener(event: Events::JWT_AUTHENTICATED)]
class JWTAuthenticatedListener
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function __invoke(JWTAuthenticatedEvent $event): void
    {
        $payload = $event->getPayload();
        $this->tenantContext->setRoles($payload['tenant_roles'] ?? []);
        $this->tenantContext->setPermissions($payload['tenant_permissions'] ?? []);
    }
}
