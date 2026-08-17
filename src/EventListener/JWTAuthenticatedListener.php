<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Security\TenantContext;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Reads tenant_roles and tenant_permissions from the validated JWT
 * and populates TenantContext so controllers can check permissions.
 *
 * Also enforces real-time deactivation: the authenticator re-loads the User
 * from the DB on every request (via the Doctrine user provider), so the user
 * behind $event->getToken() is always current, not a stale JWT-issuance-time
 * snapshot — deactivating a user takes effect on their very next request.
 */
#[AsEventListener(event: Events::JWT_AUTHENTICATED)]
class JWTAuthenticatedListener
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function __invoke(JWTAuthenticatedEvent $event): void
    {
        $user = $event->getToken()->getUser();

        if ($user instanceof User && !$user->isActive()) {
            throw new CustomUserMessageAuthenticationException('Your account has been deactivated.');
        }

        $payload = $event->getPayload();
        $this->tenantContext->setRoles($payload['tenant_roles'] ?? []);
        $this->tenantContext->setPermissions($payload['tenant_permissions'] ?? []);
    }
}
