<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use DAMA\DoctrineTestBundle\DAMADoctrineTestBundle;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Base class for integration tests with database transaction isolation.
 *
 * Uses DAMA\DoctrineTestBundle to wrap each test in a transaction
 * that is rolled back at the end, providing fast and isolated tests.
 */
abstract class IntegrationTestCase extends WebTestCase
{
    use \DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Mock the HttpClient to simulate Ollama responses without making real HTTP calls.
     * This is essential for testing OCR functionality without running the actual models.
     *
     * @param array<string, mixed> $responseData The JSON response data to return
     */
    protected function mockOllamaHttpClient(array $responseData): void
    {
        $mockClient = new MockHttpClient([
            new \Symfony\Component\HttpClient\Response\MockResponse(
                json_encode($responseData),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]
            ),
        ]);

        self::getContainer()->set(HttpClientInterface::class, $mockClient);
    }

    /**
     * Load a fixture file containing a mock Ollama response.
     *
     * @param string $fixtureName Name of the fixture file (without .json extension)
     * @return array<string, mixed> The decoded JSON response
     */
    protected function loadOllamaFixture(string $fixtureName): array
    {
        $fixturePath = __DIR__ . '/../Fixtures/Ollama/' . $fixtureName . '.json';
        
        if (!file_exists($fixturePath)) {
            throw new \RuntimeException("Ollama fixture not found: {$fixturePath}");
        }

        $content = file_get_contents($fixturePath);
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to decode Ollama fixture: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Assert that a message was dispatched to the specified transport.
     *
     * @param class-string $messageClass The message class to look for
     * @param string $transport The transport name (e.g., 'async', 'ocr')
     */
    protected function assertMessageDispatched(string $messageClass, string $transport = 'async'): void
    {
        $transport = self::getContainer()->get('messenger.transport.' . $transport);
        $envelopes = $transport->get();
        
        $found = false;
        foreach ($envelopes as $envelope) {
            if ($envelope->getMessage() instanceof $messageClass) {
                $found = true;
                break;
            }
        }
        
        $this->assertTrue($found, "Message {$messageClass} was not dispatched to transport {$transport}");
    }
}
