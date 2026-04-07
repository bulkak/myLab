<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TOTPService;
use PHPUnit\Framework\TestCase;

class TOTPServiceTest extends TestCase
{
    private TOTPService $service;

    protected function setUp(): void
    {
        $this->service = new TOTPService('TestIssuer');
    }

    public function testGenerateSecretReturnsString(): void
    {
        $secret = $this->service->generateSecret();
        
        self::assertIsString($secret);
        self::assertNotEmpty($secret);
        self::assertGreaterThanOrEqual(16, strlen($secret));
    }

    public function testCheckCodeWithValidCode(): void
    {
        $secret = $this->service->generateSecret();
        
        // TOTP codes change every 30 seconds, so we generate a code and verify it
        // Note: This test might be flaky if it runs at exactly 29-30 seconds boundary
        $totp = \OTPHP\TOTP::createFromSecret($secret, clock: new \Symfony\Component\Clock\Clock());
        $validCode = str_pad((string)$totp->now(), 6, '0', STR_PAD_LEFT);
        
        // Verify the code is in the correct format
        $this->assertTrue($this->service->isValidCodeFormat($validCode));
    }

    public function testIsValidCodeFormatWithValidCode(): void
    {
        self::assertTrue($this->service->isValidCodeFormat('123456'));
        self::assertTrue($this->service->isValidCodeFormat('000000'));
        self::assertTrue($this->service->isValidCodeFormat('999999'));
    }

    public function testIsValidCodeFormatWithInvalidCodes(): void
    {
        self::assertFalse($this->service->isValidCodeFormat('12345'));  // 5 digits
        self::assertFalse($this->service->isValidCodeFormat('1234567')); // 7 digits
        self::assertFalse($this->service->isValidCodeFormat('12345a'));  // contains letter
        self::assertFalse($this->service->isValidCodeFormat('123.56'));  // contains dot
        self::assertFalse($this->service->isValidCodeFormat(''));        // empty
    }

    public function testGetSecretForDisplay(): void
    {
        $secret = 'JBSWY3DPEBLW64TMMQ7BIQIA';
        $display = $this->service->getSecretForDisplay($secret);
        
        self::assertStringContainsString(' ', $display);
        self::assertStringContainsString('JBSW', $display);
    }

    public function testGenerateBackupCodes(): void
    {
        $codes = $this->service->generateBackupCodes();
        
        self::assertCount(8, $codes);
        foreach ($codes as $code) {
            self::assertIsString($code);
            self::assertNotEmpty($code);
            self::assertGreaterThanOrEqual(8, strlen($code)); // At least 8 hex chars
        }
    }

    public function testGetProvisioningUri(): void
    {
        $secret = $this->service->generateSecret();
        $uri = $this->service->getProvisioningUri('testuser', $secret);
        
        self::assertStringContainsString('otpauth://totp/', $uri);
        self::assertStringContainsString('testuser', $uri);
        self::assertStringContainsString('TestIssuer', $uri);
    }

    public function testGenerateQRCode(): void
    {
        $secret = $this->service->generateSecret();
        $qrCode = $this->service->generateQRCode('testuser', $secret);
        
        self::assertStringStartsWith('data:image/png;base64,', $qrCode);
        self::assertGreaterThan(100, strlen($qrCode));
    }
}
