<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\TOTPService;
use App\Tests\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;

/**
 * Full workflow integration test - Registration, Login, Upload
 */
class FullWorkflowIntegrationTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private TOTPService $totpService;
    private string $testUsername;
    private string $testSecret;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->totpService = new TOTPService('MedicalAnalyzer');
        $this->testUsername = 'integration_test_' . uniqid();
        $this->testSecret = $this->totpService->generateSecret();
        
        // Clear users
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    /**
     * Test complete registration flow
     */
    public function testCompleteRegistrationFlow(): void
    {
        $client = self::createClient();
        
        // Step 1: Go to registration page
        $client->request('GET', '/auth/register');
        self::assertHttp2xx($client->getResponse());
        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('регистр', strtolower($content));
        
        // Step 2: Submit registration form
        $client->request('POST', '/auth/register', [
            'username' => $this->testUsername
        ]);
        
        // Should redirect to QR page
        self::assertTrue($client->getResponse()->isRedirect());
        
        // Step 3: Follow to QR page
        $client->followRedirect();
        self::assertHttp2xx($client->getResponse());
        
        $qrPageContent = $client->getResponse()->getContent();
        self::assertStringContainsString('QR', $qrPageContent);
        self::assertStringContainsString('data:image/png;base64,', $qrPageContent);
        self::assertStringContainsString('Google Authenticator', $qrPageContent);
    }

    /**
     * Test registration with invalid username
     */
    public function testRegistrationWithInvalidUsernames(): void
    {
        $client = self::createClient();
        
        // Test too short
        $client->request('POST', '/auth/register', ['username' => 'ab']);
        self::assertHttp2xx($client->getResponse());
        self::assertStringContainsString('минимум', $client->getResponse()->getContent());
        
        // Test invalid characters
        $client = self::createClient();
        $client->request('POST', '/auth/register', ['username' => 'test@user!']);
        self::assertHttp2xx($client->getResponse());
        self::assertStringContainsString('буквы', $client->getResponse()->getContent());
        
        // Test empty
        $client = self::createClient();
        $client->request('POST', '/auth/register', ['username' => '']);
        self::assertHttp2xx($client->getResponse());
    }

    /**
     * Test login with non-existent user
     */
    public function testLoginWithNonExistentUser(): void
    {
        $client = self::createClient();
        $client->request('POST', '/auth/login', [
            'username' => 'nonexistent_user',
            'code' => '123456'
        ]);
        
        self::assertHttp2xx($client->getResponse());
        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('не найден', strtolower($content));
    }

    /**
     * Test TOTP code validation
     */
    public function testTOTPCodeValidation(): void
    {
        // Valid code format
        self::assertTrue($this->totpService->isValidCodeFormat('123456'));
        self::assertTrue($this->totpService->isValidCodeFormat('000000'));
        
        // Invalid code formats
        self::assertFalse($this->totpService->isValidCodeFormat('12345'));   // too short
        self::assertFalse($this->totpService->isValidCodeFormat('1234567')); // too long
        self::assertFalse($this->totpService->isValidCodeFormat('12345a'));  // contains letter
        self::assertFalse($this->totpService->isValidCodeFormat(''));        // empty
    }

    /**
     * Test QR code generation
     */
    public function testQRCodeGeneration(): void
    {
        $qrCode = $this->totpService->generateQRCode($this->testUsername, $this->testSecret);
        
        // Should be base64 encoded PNG
        self::assertStringStartsWith('data:image/png;base64,', $qrCode);
        
        // Should have substantial size (at least 1KB for valid QR)
        self::assertGreaterThan(1000, strlen($qrCode));
    }

    /**
     * Test secret key display format
     */
    public function testSecretKeyDisplay(): void
    {
        $secret = 'JBSWY3DPEBLW64TMMQ7BIQIA';
        $display = $this->totpService->getSecretForDisplay($secret);
        
        // Should have spaces
        self::assertStringContainsString(' ', $display);
        
        // Should contain original chars
        self::assertStringContainsString('JBSW', $display);
    }

    /**
     * Test provisioning URI
     */
    public function testProvisioningUri(): void
    {
        $uri = $this->totpService->getProvisioningUri($this->testUsername, $this->testSecret);
        
        // Should be valid OTP URI
        self::assertStringContainsString('otpauth://totp/', $uri);
        self::assertStringContainsString($this->testUsername, $uri);
        self::assertStringContainsString('MedicalAnalyzer', $uri);
    }

    /**
     * Test authentication pages accessibility
     */
    public function testAuthenticationPagesAccessibility(): void
    {
        $client = self::createClient();
        
        // Register page
        $client->request('GET', '/auth/register');
        self::assertHttp2xx($client->getResponse());
        
        // Login page
        $client = self::createClient();
        $client->request('GET', '/auth/login');
        self::assertHttp2xx($client->getResponse());
    }

    /**
     * Test protected pages redirect to login
     */
    public function testProtectedPagesRedirectToLogin(): void
    {
        $client = self::createClient();
        
        // Upload page should redirect
        $client->request('GET', '/upload');
        self::assertTrue($client->getResponse()->isRedirect());
        
        // History page should redirect
        $client = self::createClient();
        $client->request('GET', '/history');
        self::assertTrue($client->getResponse()->isRedirect());
        
        // Dashboard should redirect
        $client = self::createClient();
        $client->request('GET', '/');
        self::assertTrue($client->getResponse()->isRedirect());
    }

    /**
     * Test backup codes generation
     */
    public function testBackupCodesGeneration(): void
    {
        $codes = $this->totpService->generateBackupCodes();
        
        // Should have 8 codes
        self::assertCount(8, $codes);
        
        // Each code should be valid hex
        foreach ($codes as $code) {
            self::assertIsString($code);
            self::assertNotEmpty($code);
            self::assertGreaterThanOrEqual(8, strlen($code));
            self::assertTrue(ctype_xdigit($code), "Code $code is not hex");
        }
    }
}
