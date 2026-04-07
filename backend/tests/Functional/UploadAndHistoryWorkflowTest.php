<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

class UploadAndHistoryWorkflowTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private string $testUser;
    private string $testCode;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        
        // Create a test user
        $this->testUser = 'workflow_test_' . uniqid();
        $this->testCode = '000000';
    }

    public static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Cleanup
        $this->entityManager->createQuery(
            'DELETE FROM App\Entity\User u WHERE u.username LIKE :pattern'
        )->setParameter('pattern', 'workflow_test_%')
         ->execute();
    }

    public function testUploadPageRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/upload');

        // Should redirect to login
        self::assertTrue($client->getResponse()->isRedirect('/auth/login'));
    }

    public function testDashboardPageRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        // Should redirect to login
        self::assertTrue($client->getResponse()->isRedirect('/auth/login'));
    }

    public function testHistoryPageRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/history');

        // Should redirect to login
        self::assertTrue($client->getResponse()->isRedirect('/auth/login'));
    }

    public function testQRCodePageDisplaysAfterRegistration(): void
    {
        $client = self::createClient();
        
        // Go to registration
        $client->request('GET', '/auth/register');
        self::assertHttp2xx($client->getResponse());
        
        // Submit registration form
        $client->request('POST', '/auth/register', ['username' => $this->testUser]);
        self::assertTrue($client->getResponse()->isRedirect());
        
        // Follow redirect to QR page
        $client->followRedirect();
        self::assertHttp2xx($client->getResponse());
        
        // Check for QR code elements
        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('data:image/png;base64', $content);
    }
}
