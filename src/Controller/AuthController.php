<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth', format: 'json')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * POST /api/auth/token
     * Body: { "email": "...", "password": "..." }
     */
    #[Route('/token', name: 'api_auth_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->json(
                ['error' => 'invalid_request', 'message' => 'Fields email and password are required.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $user = $this->userRepository->findActiveByEmail($email);

        if ($user === null || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(
                ['error' => 'invalid_credentials', 'message' => 'Invalid email or password.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return $this->json([
            'token' => $this->jwtManager->create($user),
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    /**
     * GET /api/auth/me — requires Bearer token
     */
    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'roles' => $this->tenantContext->getRoles(),
            'permissions' => $this->tenantContext->getPermissions(),
        ]);
    }

    /**
     * POST /api/auth/register — requires ROLE_GROUP_ADMIN
     * Body: { "email": "...", "password": "...", "firstName": "...", "lastName": "...", "roles": [...], "permissions": [...] }
     */
    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        if (!in_array('ROLE_GROUP_ADMIN', $this->tenantContext->getRoles(), true)) {
            return $this->json(
                ['error' => 'forbidden', 'message' => 'Only admins can create users.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? ''));
        $roles = array_values(array_filter((array) ($data['roles'] ?? []), 'is_string'));
        $permissions = array_values(array_filter((array) ($data['permissions'] ?? []), 'is_string'));

        $errors = [];
        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        if ($firstName === '') {
            $errors['firstName'] = 'First name is required.';
        }
        if ($lastName === '') {
            $errors['lastName'] = 'Last name is required.';
        }
        if ($errors !== []) {
            return $this->json(
                ['error' => 'validation_failed', 'errors' => $errors],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($this->userRepository->findActiveByEmail($email) !== null) {
            return $this->json(
                ['error' => 'email_taken', 'message' => 'That email address is already in use.'],
                Response::HTTP_CONFLICT,
            );
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setTenantRoles($roles ?: ['CONTENT_ADMIN']);
        $user->setPermissions($permissions ?: ['content:create', 'content:read', 'content:update']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $this->json([
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'roles' => $user->getTenantRoles(),
            'permissions' => $user->getPermissions(),
        ], Response::HTTP_CREATED);
    }
}
