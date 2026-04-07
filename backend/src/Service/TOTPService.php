<?php

namespace App\Service;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

class TOTPService
{
    private string $issuer;
    private ClockInterface $clock;

    public function __construct(string $issuer = 'myLab')
    {
        $this->issuer = $issuer;
        $this->clock = new Clock();
    }

    /**
     * Generate a new TOTP secret
     */
    public function generateSecret(): string
    {
        return TOTP::generate(clock: $this->clock)->getSecret();
    }

    /**
     * Check if a code is valid for a given secret
     */
    public function checkCode(string $secret, string $code): bool
    {
        $totp = TOTP::createFromSecret($secret, clock: $this->clock);
        return $totp->verify($code);
    }

    /**
     * Generate provisioning URI for QR code
     */
    public function getProvisioningUri(string $username, string $secret): string
    {
        $totp = TOTP::createFromSecret($secret, clock: $this->clock);
        $totp->setLabel($username);
        $totp->setIssuer($this->issuer);
        
        return $totp->getProvisioningUri();
    }

    /**
     * Generate QR code for Google Authenticator
     */
    public function generateQRCode(string $username, string $secret): string
    {
        $otpUri = $this->getProvisioningUri($username, $secret);

        // Use GDLibRenderer (no extra backend needed)
        $renderer = new GDLibRenderer(300);
        $writer = new Writer($renderer);
        $qrCode = $writer->writeString($otpUri);
        
        return 'data:image/png;base64,' . base64_encode($qrCode);
    }

    /**
     * Get the secret key for display (for manual entry)
     */
    public function getSecretForDisplay(string $secret): string
    {
        return chunk_split($secret, 4, ' ');
    }

    /**
     * Generate backup codes for user
     * @return string[] Array of 8 backup codes
     */
    public function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = bin2hex(random_bytes(4));
        }
        return $codes;
    }

    /**
     * Validate code format (6 digits)
     */
    public function isValidCodeFormat(string $code): bool
    {
        return preg_match('/^\d{6}$/', $code) === 1;
    }
}

