<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Create an admin user with full permissions',
)]
class CreateAdminUserCommand extends Command
{
    private const ADMIN_ROLES = ['ROLE_GROUP_ADMIN'];
    private const ADMIN_PERMISSIONS = ['content:create', 'content:read', 'content:update'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Create Admin User');

        $email = $io->ask('Email', null, function (?string $value): string {
            $value = trim((string) $value);
            if ($value === '' || !filter_var($value, \FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('A valid email address is required.');
            }

            return $value;
        });

        if ($this->userRepository->findActiveByEmail($email) !== null) {
            $io->error("A user with email {$email} already exists.");

            return Command::FAILURE;
        }

        $password = $io->askHidden('Password (min 8 characters)', function (?string $value): string {
            if (strlen((string) $value) < 8) {
                throw new \RuntimeException('Password must be at least 8 characters.');
            }

            return (string) $value;
        });

        $confirm = $io->askHidden('Confirm password', function (?string $value): string {
            return (string) $value;
        });

        if ($password !== $confirm) {
            $io->error('Passwords do not match.');

            return Command::FAILURE;
        }

        $firstName = $io->ask('First name', null, function (?string $value): string {
            $value = trim((string) $value);
            if ($value === '') {
                throw new \RuntimeException('First name is required.');
            }

            return $value;
        });

        $lastName = $io->ask('Last name', null, function (?string $value): string {
            $value = trim((string) $value);
            if ($value === '') {
                throw new \RuntimeException('Last name is required.');
            }

            return $value;
        });

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setTenantRoles(self::ADMIN_ROLES);
        $user->setPermissions(self::ADMIN_PERMISSIONS);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success("Admin user created: {$email}");
        $io->definitionList(
            ['ID' => (string) $user->getId()],
            ['Email' => $user->getEmail()],
            ['Name' => "{$firstName} {$lastName}"],
            ['Roles' => implode(', ', self::ADMIN_ROLES)],
            ['Permissions' => implode(', ', self::ADMIN_PERMISSIONS)],
        );

        return Command::SUCCESS;
    }
}
