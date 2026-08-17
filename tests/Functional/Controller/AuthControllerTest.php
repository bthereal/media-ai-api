<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Security\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The `api` firewall is fully disabled in the test env (security.yaml when@test),
 * so JWTs are never validated and JWTAuthenticatedListener never fires — meaning
 * TenantContext can't be populated via a real Bearer token in tests. AuthController
 * checks TenantContext directly (not PermissionChecker's null-token bypass used
 * elsewhere), so tests set TenantContext's roles directly via the container instead,
 * matching how this test env actually behaves.
 *
 * Note: TenantContext is a ResetInterface service, and Symfony's Kernel::boot()
 * resets ALL such services at the start of every request after the first one on an
 * already-booted kernel (a deliberate per-request isolation guarantee — not related
 * to KernelBrowser's "reboot" setting, which is a separate mechanism). This means
 * actAsAdmin()/actAsEditor() only survives for a single $client->request() call —
 * calling it again between two requests in the same test does NOT work, since the
 * second request's boot() wipes it again before the controller runs. Tests that need
 * to assert something about a second call (e.g. idempotency) do it by pre-seeding
 * entity state directly and making one request, not by chaining two requests.
 */
class AuthControllerTest extends WebTestCase
{
    private const REGISTER_ENDPOINT = '/api/auth/register';
    private const USERS_ENDPOINT = '/api/auth/users';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        static::createClient();

        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE users RESTART IDENTITY CASCADE');
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    private function createUser(array $tenantRoles, array $permissions, string $email): User
    {
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));
        $user->setTenantRoles($tenantRoles);
        $user->setPermissions($permissions);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Always fetches TenantContext fresh from the container rather than caching it —
     * the test client reboots the kernel (and thus the container) between requests,
     * so a reference captured once in setUp() goes stale after the first request.
     */
    private function tenantContext(): TenantContext
    {
        return static::getContainer()->get(TenantContext::class);
    }

    private function actAsAdmin(): void
    {
        $this->tenantContext()->setRoles(['ROLE_GROUP_ADMIN']);
        $this->tenantContext()->setPermissions([]);
    }

    private function actAsEditor(): void
    {
        $this->tenantContext()->setRoles(['CONTENT_ADMIN']);
        $this->tenantContext()->setPermissions(['content:read']);
    }

    /**
     * Like actAsAdmin(), but also sets a real security token wrapping $user, so
     * $this->getUser() resolves in the controller — needed for the self-deactivation
     * guard, which compares the target id against the requester's own id.
     */
    private function actAsAdminUser(User $user): void
    {
        static::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'api', $user->getRoles()),
        );
        $this->tenantContext()->setRoles(['ROLE_GROUP_ADMIN']);
        $this->tenantContext()->setPermissions([]);
    }

    public function testRegisterRejectsNonAdmin(): void
    {
        $this->actAsEditor();

        $client = static::getClient();
        $client->request('POST', self::REGISTER_ENDPOINT, content: json_encode([
            'email' => 'new@example.com',
            'password' => 'password123',
            'firstName' => 'New',
            'lastName' => 'User',
            'role' => 'editor',
        ]));

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testRegisterRejectsUnknownRole(): void
    {
        $this->actAsAdmin();

        $client = static::getClient();
        $client->request('POST', self::REGISTER_ENDPOINT, content: json_encode([
            'email' => 'new@example.com',
            'password' => 'password123',
            'firstName' => 'New',
            'lastName' => 'User',
            'role' => 'superuser',
        ]));

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('role', $body['errors']);
    }

    public function testRegisterWithEditorRolePersistsResolvedRolesAndPermissions(): void
    {
        $this->actAsAdmin();

        $client = static::getClient();
        $client->request('POST', self::REGISTER_ENDPOINT, content: json_encode([
            'email' => 'new-editor@example.com',
            'password' => 'password123',
            'firstName' => 'New',
            'lastName' => 'Editor',
            'role' => 'editor',
        ]));

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(['CONTENT_ADMIN'], $body['roles']);
        $this->assertSame(['content:create', 'content:read', 'content:update', 'playlist:manage'], $body['permissions']);
    }

    public function testRegisterWithAdminRolePersistsFullAccessRole(): void
    {
        $this->actAsAdmin();

        $client = static::getClient();
        $client->request('POST', self::REGISTER_ENDPOINT, content: json_encode([
            'email' => 'new-admin@example.com',
            'password' => 'password123',
            'firstName' => 'New',
            'lastName' => 'Admin',
            'role' => 'admin',
        ]));

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(['ROLE_GROUP_ADMIN'], $body['roles']);
        $this->assertSame([], $body['permissions']);
    }

    public function testUsersRejectsNonAdmin(): void
    {
        $this->actAsEditor();

        $client = static::getClient();
        $client->request('GET', self::USERS_ENDPOINT);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testUsersReturnsAllUsersWithoutPasswordForAdmin(): void
    {
        $this->createUser(['ROLE_GROUP_ADMIN'], [], 'admin@example.com');
        $this->createUser(['CONTENT_ADMIN'], ['content:read'], 'editor@example.com');
        $this->actAsAdmin();

        $client = static::getClient();
        $client->request('GET', self::USERS_ENDPOINT);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertCount(2, $body['items']);
        foreach ($body['items'] as $item) {
            $this->assertArrayNotHasKey('password', $item);
        }
    }

    public function testTokenRejectsDeactivatedUser(): void
    {
        $user = $this->createUser(['CONTENT_ADMIN'], ['content:read'], 'deactivated@example.com');
        $user->deactivate();
        $this->em->flush();

        $client = static::getClient();
        $client->request('POST', '/api/auth/token', content: json_encode([
            'email' => 'deactivated@example.com',
            'password' => 'password123',
        ]));

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testUserGetRejectsNonAdmin(): void
    {
        $target = $this->createUser(['CONTENT_ADMIN'], ['content:read'], 'target@example.com');
        $this->actAsEditor();

        $client = static::getClient();
        $client->request('GET', self::USERS_ENDPOINT . '/' . $target->getId());

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testUserGetReturns404ForUnknownId(): void
    {
        $this->actAsAdmin();

        $client = static::getClient();
        $client->request('GET', self::USERS_ENDPOINT . '/550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testUserGetReturnsUserForAdmin(): void
    {
        $target = $this->createUser(['CONTENT_ADMIN'], ['content:read'], 'target@example.com');
        $this->actAsAdmin();

        $client = static::getClient();
        $client->request('GET', self::USERS_ENDPOINT . '/' . $target->getId());

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('target@example.com', $body['email']);
        $this->assertNull($body['deactivatedAt']);
    }

    public function testDeactivateRejectsNonAdmin(): void
    {
        $target = $this->createUser(['CONTENT_ADMIN'], ['content:read'], 'target@example.com');
        $this->actAsEditor();

        $client = static::getClient();
        $client->request('POST', self::USERS_ENDPOINT . '/' . $target->getId() . '/deactivate');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testDeactivateSetsDeactivatedAtForAdmin(): void
    {
        $target = $this->createUser(['CONTENT_ADMIN'], ['content:read'], 'target@example.com');
        $this->actAsAdmin();

        $client = static::getClient();
        $client->request('POST', self::USERS_ENDPOINT . '/' . $target->getId() . '/deactivate');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotNull($body['deactivatedAt']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($target->getId());
        $this->assertFalse($reloaded->isActive());
    }

    public function testDeactivateIsIdempotentForAlreadyDeactivatedUser(): void
    {
        $target = $this->createUser(['CONTENT_ADMIN'], ['content:read'], 'target@example.com');
        $target->deactivate();
        $this->em->flush();
        $this->actAsAdmin();

        $client = static::getClient();
        $client->request('POST', self::USERS_ENDPOINT . '/' . $target->getId() . '/deactivate');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testDeactivateBlocksSelfDeactivation(): void
    {
        $admin = $this->createUser(['ROLE_GROUP_ADMIN'], [], 'self-admin@example.com');
        $this->actAsAdminUser($admin);

        $client = static::getClient();
        $client->request('POST', self::USERS_ENDPOINT . '/' . $admin->getId() . '/deactivate');

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('cannot_deactivate_self', $body['error']);
    }

    public function testReactivateRejectsNonAdmin(): void
    {
        $target = $this->createUser(['CONTENT_ADMIN'], ['content:read'], 'target@example.com');
        $target->deactivate();
        $this->em->flush();
        $this->actAsEditor();

        $client = static::getClient();
        $client->request('POST', self::USERS_ENDPOINT . '/' . $target->getId() . '/reactivate');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testReactivateClearsDeactivatedAtForAdmin(): void
    {
        $target = $this->createUser(['CONTENT_ADMIN'], ['content:read'], 'target@example.com');
        $target->deactivate();
        $this->em->flush();
        $this->actAsAdmin();

        $client = static::getClient();
        $client->request('POST', self::USERS_ENDPOINT . '/' . $target->getId() . '/reactivate');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertNull($body['deactivatedAt']);
    }
}
