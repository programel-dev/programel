<?php

namespace App\Tests\Command;

use App\Command\CreateUserCommand;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CreateUserCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private UserPasswordHasherInterface&MockObject $passwordHasher;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $this->passwordHasher
            ->method('hashPassword')
            ->willReturn('hashed_password');

        $command = new CreateUserCommand($this->entityManager, $this->passwordHasher);

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($application->find('app:create-user'));
    }

    public function testCreateUser(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->entityManager->expects($this->once())->method('persist')->with(
            $this->callback(function (User $user): bool {
                return 'test@example.com' === $user->getEmail()
                    && 'hashed_password' === $user->getPassword()
                    && $user->getRoles() === ['ROLE_USER'];
            }),
        );
        $this->entityManager->expects($this->once())->method('flush');

        $this->commandTester->execute([
            'email' => 'test@example.com',
            'password' => 'secret123',
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('test@example.com', $this->commandTester->getDisplay());
    }

    public function testCreateAdminUser(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->entityManager->expects($this->once())->method('persist')->with(
            $this->callback(function (User $user): bool {
                return in_array('ROLE_ADMIN', $user->getRoles(), true);
            }),
        );

        $this->commandTester->execute([
            'email' => 'admin@example.com',
            'password' => 'secret123',
            '--admin' => true,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('ROLE_ADMIN', $this->commandTester->getDisplay());
    }

    public function testRejectDuplicateEmail(): void
    {
        $existingUser = new User();
        $existingUser->setEmail('existing@example.com');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($existingUser);
        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->entityManager->expects($this->never())->method('persist');

        $this->commandTester->execute([
            'email' => 'existing@example.com',
            'password' => 'secret123',
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('already exists', $this->commandTester->getDisplay());
    }

    public function testRejectInvalidEmail(): void
    {
        $this->entityManager->expects($this->never())->method('persist');

        $this->commandTester->execute([
            'email' => 'not-an-email',
            'password' => 'secret123',
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid email', $this->commandTester->getDisplay());
    }
}
