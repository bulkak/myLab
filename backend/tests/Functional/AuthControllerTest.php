<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

class AuthControllerTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        
        // Clear users table
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    public function testRegistrationPageLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/auth/register');

        self::assertHttp2xx($client->getResponse());
        self::assertResponseHasContent($client->getResponse());
    }

    public function testRegistrationWithValidUsername(): void
    {
        $client = self::createClient();
        $client->request('POST', '/auth/register', ['username' => 'testuser123']);

        // Should redirect to QR code page
        self::assertTrue($client->getResponse()->isRedirect('/auth/register/qr'));
    }

    public function testRegistrationWithInvalidUsername(): void
    {
        $client = self::createClient();
        
        // Too short
        $client->request('POST', '/auth/register', ['username' => 'ab']);
        self::assertHttp2xx($client->getResponse());
        self::assertStringContainsString('минимум', $client->getResponse()->getContent());
        
        // Invalid characters
        $client = self::createClient();
        $client->request('POST', '/auth/register', ['username' => 'test@user']);
        self::assertHttp2xx($client->getResponse());
        self::assertStringContainsString('буквы, цифры', $client->getResponse()->getContent());
    }

    public function testRegistrationWithDuplicateUsername(): void
    {
        // Create first user
        $user = new User();
        $user->setUsername('existinguser');
        $user->setTotpSecret('test_secret');
        $user->setStatus(User::STATUS_ACTIVE);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Try to register with same username
        $client = self::createClient();
        $client->request('POST', '/auth/register', ['username' => 'existinguser']);

        self::assertHttp2xx($client->getResponse());
        self::assertStringContainsString('занят', $client->getResponse()->getContent());
    }

    public function testLoginPageLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/auth/login');

        self::assertHttp2xx($client->getResponse());
        self::assertResponseHasContent($client->getResponse());
    }

    public function testLogout(): void
    {
        $client = self::createClient();
        $client->request('POST', '/auth/logout');

        // Should redirect to login
        self::assertTrue($client->getResponse()->isRedirect());
    }
}
