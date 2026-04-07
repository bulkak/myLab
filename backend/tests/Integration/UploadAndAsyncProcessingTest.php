<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Analysis;
use App\Entity\User;
use App\Message\OCRJob;
use App\Service\TOTPService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Integration tests for async file upload and OCR processing.
 *
 * These tests verify that:
 * - File uploads are processed asynchronously (via RabbitMQ)
 * - OCRJob is dispatched to the async transport
 * - The model can be specified for reprocessing
 *
 * @group integration
 */
class UploadAndAsyncProcessingTest extends IntegrationTestCase
{
    private TOTPService $totpService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totpService = self::getContainer()->get(TOTPService::class);
    }

    /**
     * Test that file upload creates an analysis and dispatches OCRJob to async transport.
     * This is the core TDD test - it will fail until we configure messenger to use async transport.
     */
    public function testFileUploadDispatchesOCRJobToAsyncTransport(): void
    {
        // Create a user
        $user = $this->createTestUser('testuser_' . uniqid());
        
        // Get the client and login
        $client = static::createClient();
        $this->loginUser($client, $user);
        
        // Create a test file
        $testFilePath = $this->createTestImageFile();
        
        // Upload the file
        $client->request(
            'POST',
            '/upload',
            [],
            ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile(
                $testFilePath,
                'test_analysis.png',
                'image/png',
                null,
                true
            )],
            ['HTTP_ACCEPT' => 'application/json']
        );
        
        $response = $client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success'] ?? false, 'Upload should succeed');
        $this->assertArrayHasKey('analysisId', $data);
        
        // Verify analysis was created with PENDING status
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $analysis = $entityManager->getRepository(Analysis::class)->find($data['analysisId']);
        $this->assertNotNull($analysis);
        $this->assertEquals(Analysis::STATUS_PENDING, $analysis->getStatus());
        
        // Assert that OCRJob was dispatched to the 'async' transport (RabbitMQ)
        // This will fail until we configure messenger.yaml to route OCRJob to async transport
        $this->assertOCRJobDispatchedToAsyncTransport($data['analysisId']);
    }

    /**
     * Test that reprocessing endpoint accepts a model parameter and dispatches job with it.
     * This test will fail until we implement the reprocess endpoint.
     */
    public function testReprocessEndpointAcceptsModelParameter(): void
    {
        // Create a user and analysis
        $user = $this->createTestUser('testuser_' . uniqid());
        $analysis = $this->createTestAnalysis($user, Analysis::STATUS_COMPLETED);
        
        // Get the client and login
        $client = static::createClient();
        $this->loginUser($client, $user);
        
        // Request reprocessing with a specific model
        $client->request(
            'POST',
            '/analysis/' . $analysis->getId() . '/reprocess',
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['model' => 'qwen2-vl:2b'])
        );
        
        $response = $client->getResponse();
        
        // This will fail until we implement the endpoint
        $this->assertEquals(200, $response->getStatusCode(), 'Reprocess endpoint should be implemented');
        
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success'] ?? false);
        
        // Verify analysis status was reset to PENDING
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $updatedAnalysis = $entityManager->getRepository(Analysis::class)->find($analysis->getId());
        $this->assertEquals(Analysis::STATUS_PENDING, $updatedAnalysis->getStatus());
        
        // Assert OCRJob was dispatched with the specific model
        $this->assertOCRJobDispatchedWithModel($analysis->getId(), 'qwen2-vl:2b');
    }

    /**
     * Test that OCRJob can be created with a specific model parameter.
     * This verifies the OCRJob message structure supports model selection.
     */
    public function testOCRJobSupportsModelParameter(): void
    {
        // This test verifies that OCRJob message can be created with model parameter
        // It will fail until we add the model parameter to OCRJob constructor
        
        $job = new OCRJob(
            analysisId: 123,
            filePath: '/path/to/file.png',
            userId: 456,
            model: 'qwen2-vl:2b'  // This parameter should be supported
        );
        
        $this->assertEquals(123, $job->getAnalysisId());
        $this->assertEquals('/path/to/file.png', $job->getFilePath());
        $this->assertEquals(456, $job->getUserId());
        
        // This assertion will fail until we implement getModel() method
        $this->assertEquals('qwen2-vl:2b', $job->getModel(), 'OCRJob should support model parameter');
    }

    /**
     * Test that OcrManager can process with different models.
     * This will fail until we implement the OcrManager with model support.
     */
    public function testOcrManagerProcessesWithSpecifiedModel(): void
    {
        // Mock Ollama HTTP client with successful response
        $fixture = $this->loadOllamaFixture('qwen2_vl_success');
        $mockClient = new MockHttpClient([
            new MockResponse(
                json_encode($fixture),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]
            ),
        ]);
        
        self::getContainer()->set(HttpClientInterface::class, $mockClient);
        
        // Get OcrManager from container
        // This will fail until we create OcrManager service
        $ocrManager = self::getContainer()->get('App\Service\OcrManager');
        $this->assertInstanceOf('App\Service\OcrManager', $ocrManager);
        
        // Process with specific model
        $testFilePath = $this->createTestImageFile();
        $result = $ocrManager->process($testFilePath, 'qwen2-vl:2b');
        
        $this->assertNotNull($result);
        $this->assertIsArray($result);
    }

    /**
     * Helper method to assert that OCRJob was dispatched to async transport.
     */
    private function assertOCRJobDispatchedToAsyncTransport(int $analysisId): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        $envelopes = $transport->get();
        
        $found = false;
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof OCRJob && $message->getAnalysisId() === $analysisId) {
                $found = true;
                break;
            }
        }
        
        $this->assertTrue($found, "OCRJob for analysis {$analysisId} was not dispatched to async transport");
    }

    /**
     * Helper method to assert that OCRJob was dispatched with specific model.
     */
    private function assertOCRJobDispatchedWithModel(int $analysisId, string $expectedModel): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        $envelopes = $transport->get();
        
        $found = false;
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof OCRJob && 
                $message->getAnalysisId() === $analysisId &&
                $message->getModel() === $expectedModel) {
                $found = true;
                break;
            }
        }
        
        $this->assertTrue($found, "OCRJob for analysis {$analysisId} with model {$expectedModel} was not dispatched");
    }

    /**
     * Create a test user with TOTP authentication.
     */
    private function createTestUser(string $username): User
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        
        $user = new User();
        $user->setUsername($username);
        $user->setTotpSecret($this->totpService->generateSecret());
        $user->setStatus(User::STATUS_ACTIVE);
        
        $entityManager->persist($user);
        $entityManager->flush();
        
        return $user;
    }

    /**
     * Create a test analysis record.
     */
    private function createTestAnalysis(User $user, string $status): Analysis
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        
        $analysis = new Analysis();
        $analysis->setUser($user);
        $analysis->setFilePath('/test/path/to/file.png');
        $analysis->setStatus($status);
        $analysis->setTitle('Test Analysis');
        
        $entityManager->persist($analysis);
        $entityManager->flush();
        
        return $analysis;
    }

    /**
     * Login the user via TOTP.
     */
    private function loginUser(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, User $user): void
    {
        // First, submit login with username
        $client->request('POST', '/auth/login', ['username' => $user->getUsername()]);
        
        // Get TOTP code and verify
        $totp = $this->totpService->createTOTP($user->getTotpSecret());
        $code = $totp->now();
        
        $client->request('POST', '/auth/verify', [
            'username' => $user->getUsername(),
            'code' => $code,
        ]);
        
        $this->assertTrue($client->getResponse()->isRedirect() || $client->getResponse()->isSuccessful());
    }

    /**
     * Create a simple test image file.
     */
    private function createTestImageFile(): string
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = $tempDir . '/test_analysis_' . uniqid() . '.png';
        
        // Create a simple 1x1 PNG image
        $image = imagecreatetruecolor(100, 100);
        $bgColor = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $bgColor);
        
        // Add some text to simulate a document
        $textColor = imagecolorallocate($image, 0, 0, 0);
        imagestring($image, 5, 10, 10, 'Test Analysis', $textColor);
        
        imagepng($image, $tempFile);
        imagedestroy($image);
        
        return $tempFile;
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $tempDir = sys_get_temp_dir();
        foreach (glob($tempDir . '/test_analysis_*.png') as $file) {
            @unlink($file);
        }
        
        parent::tearDown();
    }
}
