<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AnalysisParserService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AnalysisParserServiceTest extends TestCase
{
    private AnalysisParserService $service;

    protected function setUp(): void
    {
        $this->service = new AnalysisParserService(new NullLogger());
    }

    public function testParseValidAnalysisJson(): void
    {
        $json = json_encode([
            'title' => 'Blood Test',
            'analysisDate' => '2026-04-05',
            'metrics' => [
                [
                    'name' => 'Hemoglobin',
                    'value' => '13.5',
                    'unit' => 'g/dL',
                    'referenceMin' => '12.0',
                    'referenceMax' => '17.0',
                    'isAboveNormal' => false,
                    'isBelowNormal' => false,
                ]
            ]
        ]);

        $result = $this->service->parse($json);

        self::assertEquals('Blood Test', $result['title']);
        self::assertCount(1, $result['metrics']);
        self::assertEquals('Hemoglobin', $result['metrics'][0]['name']);
        self::assertEquals('13.5', $result['metrics'][0]['value']);
    }

    public function testParseHandlesEmptyMetrics(): void
    {
        $json = json_encode([
            'title' => 'Test',
            'analysisDate' => '2026-04-05',
            'metrics' => []
        ]);

        $result = $this->service->parse($json);

        self::assertIsArray($result);
        self::assertIsArray($result['metrics']);
    }

    public function testParseHandlesInvalidJson(): void
    {
        $invalidJson = 'not valid json {';

        $result = $this->service->parse($invalidJson);

        self::assertIsArray($result);
        self::assertIsArray($result['metrics']);
    }
}
