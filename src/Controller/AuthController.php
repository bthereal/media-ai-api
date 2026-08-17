<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UserDto;
use App\Dto\UserListDto;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\RoleCatalog;
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
    ) {
    }

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
     * Body: { "email": "...", "password": "...", "firstName": "...", "lastName": "...", "role": "admin"|"editor" }
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
        $role = (string) ($data['role'] ?? '');

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
        if (!RoleCatalog::isValid($role)) {
            $errors['role'] = 'A valid role is required.';
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

        $resolved = RoleCatalog::resolve($role);

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setTenantRoles($resolved['roles']);
        $user->setPermissions($resolved['permissions']);

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

    /**
     * GET /api/auth/users — requires ROLE_GROUP_ADMIN
     */
    #[Route('/users', name: 'api_auth_users', methods: ['GET'])]
    public function users(): JsonResponse
    {
        if (!in_array('ROLE_GROUP_ADMIN', $this->tenantContext->getRoles(), true)) {
            return $this->json(
                ['error' => 'forbidden', 'message' => 'Only admins can view users.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $users = $this->userRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->json(new UserListDto(
            ok: true,
            items: array_map(UserDto::fromEntity(...), $users),
        ));
    }

    /**
     * GET /api/auth/users/{id} — requires ROLE_GROUP_ADMIN
     */
    #[Route('/users/{id}', name: 'api_auth_user_get', methods: ['GET'])]
    public function userGet(string $id): JsonResponse
    {
        if (!in_array('ROLE_GROUP_ADMIN', $this->tenantContext->getRoles(), true)) {
            return $this->json(
                ['error' => 'forbidden', 'message' => 'Only admins can view users.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $user = $this->userRepository->find($id);

        if (null === $user) {
            return $this->json(['error' => 'not_found', 'message' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(UserDto::fromEntity($user));
    }

    /**
     * POST /api/auth/users/{id}/deactivate — requires ROLE_GROUP_ADMIN
     */
    #[Route('/users/{id}/deactivate', name: 'api_auth_user_deactivate', methods: ['POST'])]
    public function userDeactivate(string $id): JsonResponse
    {
        if (!in_array('ROLE_GROUP_ADMIN', $this->tenantContext->getRoles(), true)) {
            return $this->json(
                ['error' => 'forbidden', 'message' => 'Only admins can deactivate users.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $user = $this->userRepository->find($id);

        if (null === $user) {
            return $this->json(['error' => 'not_found', 'message' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User && (string) $user->getId() === (string) $currentUser->getId()) {
            return $this->json(
                ['error' => 'cannot_deactivate_self', 'message' => 'You cannot deactivate your own account.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $user->deactivate();
        $this->entityManager->flush();

        return $this->json(UserDto::fromEntity($user));
    }

    /**
     * POST /api/auth/users/{id}/reactivate — requires ROLE_GROUP_ADMIN
     */
    #[Route('/users/{id}/reactivate', name: 'api_auth_user_reactivate', methods: ['POST'])]
    public function userReactivate(string $id): JsonResponse
    {
        if (!in_array('ROLE_GROUP_ADMIN', $this->tenantContext->getRoles(), true)) {
            return $this->json(
                ['error' => 'forbidden', 'message' => 'Only admins can reactivate users.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $user = $this->userRepository->find($id);

        if (null === $user) {
            return $this->json(['error' => 'not_found', 'message' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        $user->reactivate();
        $this->entityManager->flush();

        return $this->json(UserDto::fromEntity($user));
    }
}
