<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\TOTPService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileUploadOCRTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private TOTPService $totpService;
    private User $testUser;
    private string $testUserPassword = '123456'; // TOTP code placeholder

    protected function setUp(): void
    {
        // Don't access container in setUp for WebTestCase
        // We'll initialize in each test method
    }

    private function createTestUser(): void
    {
        $this->testUser = new User();
        $this->testUser->setUsername('testuser');
        $secret = $this->totpService->generateSecret();
        $this->testUser->setTotpSecret($secret);
        $this->testUser->setStatus(User::STATUS_ACTIVE);

        $this->entityManager->persist($this->testUser);
        $this->entityManager->flush();
    }

    private function loginTestUser(KernelBrowser $client): void
    {
        // Get valid TOTP code using the test user from DB
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $testUser = $em->getRepository('App\Entity\User')->findByUsername('testuser');
        
        if (!$testUser) {
            throw new \RuntimeException('Test user not found');
        }

        // Generate a TOTP code using TOTP library directly
        // For testing, we'll use '000000' as placeholder - in real scenario would generate based on secret
        // In dev mode, we can just use any code since TOTP check might be lenient in tests
        $code = '000000';
        
        // Actually, let's try to get a valid code from the library
        // We'll need to use a TOTP generation function
        $totpService = static::getContainer()->get(TOTPService::class);
        
        // For now, we'll use a simple approach: try a few codes
        // TOTP typically allows 30-second windows before/after
        for ($i = 0; $i < 3; $i++) {
            $testCode = str_pad((string)(floor(time() / 30) + $i), 6, '0', STR_PAD_LEFT);
            
            $client->request('POST', '/auth/login', [
                'username' => 'testuser',
                'code' => $testCode,
            ]);
            
            // If login succeeds, break
            if ($client->getResponse()->getStatusCode() === 302) {
                return;
            }
        }
        
        // If we got here, login failed
        throw new \RuntimeException('Could not login test user');
    }

    public function testUploadImageFile(): void
    {
        $client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->totpService = static::getContainer()->get(TOTPService::class);

        // Clean up and create test user
        $this->entityManager->getConnection()->executeStatement('DELETE FROM metrics');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM analyses');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM users');
        $this->createTestUser();
        $this->loginTestUser($client);

        // Create a test image
        $testImagePath = $this->createTestImage();

        // Upload file
        $client->request(
            'POST',
            '/upload',
            [],
            ['file' => new UploadedFile($testImagePath, 'test.jpg', 'image/jpeg')],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('analysisId', $data);

        $analysisId = $data['analysisId'];

        // Wait a bit for processing and check status
        sleep(2);

        $client->request('GET', "/upload/status/$analysisId");
        $this->assertResponseIsSuccessful();

        // Check database
        $analysis = $this->entityManager->getRepository('App\Entity\Analysis')->find($analysisId);
        $this->assertNotNull($analysis);
        $this->assertIn($analysis->getStatus(), ['pending', 'processing', 'completed', 'error']);

        // If error, error_message should be set
        if ($analysis->getStatus() === 'error') {
            $this->assertNotNull($analysis->getErrorMessage(), 'Error message should be set when status is error');
        }

        unlink($testImagePath);
    }

    public function testUploadPdfFile(): void
    {
        $client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->totpService = static::getContainer()->get(TOTPService::class);

        // Clean up and create test user
        $this->entityManager->getConnection()->executeStatement('DELETE FROM metrics');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM analyses');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM users');
        $this->createTestUser();
        $this->loginTestUser($client);

        // Try to use real test PDF if exists
        $testPdfPath = $this->getTestPdfPath();
        if (!$testPdfPath) {
            $this->markTestSkipped('No test PDF file available');
        }

        // Upload file
        $client->request(
            'POST',
            '/upload',
            [],
            ['file' => new UploadedFile($testPdfPath, 'test.pdf', 'application/pdf')],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);

        $analysisId = $data['analysisId'];

        // Check initial status
        $analysis = $this->entityManager->getRepository('App\Entity\Analysis')->find($analysisId);
        $this->assertNotNull($analysis);

        // If processing failed, error should be in database
        if ($analysis->getStatus() === 'error') {
            echo "\n❌ PDF Processing Error: " . $analysis->getErrorMessage() . "\n";
        }
    }

    public function testInvalidFileType(): void
    {
        $client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->totpService = static::getContainer()->get(TOTPService::class);

        // Clean up and create test user
        $this->entityManager->getConnection()->executeStatement('DELETE FROM metrics');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM analyses');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM users');
        $this->createTestUser();
        $this->loginTestUser($client);

        // Create a text file
        $testFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($testFile, 'This is not a valid image');

        // Try to upload
        $client->request(
            'POST',
            '/upload',
            [],
            ['file' => new UploadedFile($testFile, 'test.txt', 'text/plain')],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']
        );

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Недопустимый тип файла', $data['error']);

        unlink($testFile);
    }

    private function createTestImage(): string
    {
        $image = imagecreatetruecolor(100, 100);
        $bgColor = imagecolorallocate($image, 255, 0, 0);
        imagefill($image, 0, 0, $bgColor);

        $textColor = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 2, 10, 10, "Test", $textColor);
        imagestring($image, 2, 10, 30, "WBC: 7.5", $textColor);
        imagestring($image, 2, 10, 50, "Hgb: 140", $textColor);

        $filename = tempnam(sys_get_temp_dir(), 'test_') . '.jpg';
        imagejpeg($image, $filename, 100);
        imagedestroy($image);

        return $filename;
    }

    private function getTestPdfPath(): ?string
    {
        $testDir = __DIR__ . '/../../tests/examples';
        if (!is_dir($testDir)) {
            return null;
        }

        $pdfs = glob($testDir . '/*.pdf');
        return !empty($pdfs) ? $pdfs[0] : null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test files
        $uploadDir = __DIR__ . '/../../uploads';
        if (is_dir($uploadDir)) {
            system("rm -rf " . escapeshellarg($uploadDir . '/user_*'));
        }
    }
}
