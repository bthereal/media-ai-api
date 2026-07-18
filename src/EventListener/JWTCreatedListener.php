<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Injects tenant_roles and tenant_permissions into the JWT payload from the User entity,
 * so the existing JWTAuthenticatedListener + TenantContext chain works unchanged.
 */
#[AsEventListener(event: Events::JWT_CREATED)]
class JWTCreatedListener
{
    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $payload = $event->getData();
        $payload['tenant_roles'] = $user->getTenantRoles();
        $payload['tenant_permissions'] = $user->getPermissions();

        $event->setData($payload);
    }
}
