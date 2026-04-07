<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for OCR processing queue.
 *
 * Supports model selection for reprocessing documents with different OCR engines.
 */
class OCRJob
{
    public function __construct(
        private int $analysisId,
        private string $filePath,
        private int $userId,
        private ?string $model = null
    ) {
    }

    public function getAnalysisId(): int
    {
        return $this->analysisId;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * Get the OCR model to use for processing.
     *
     * @return string|null Model name (e.g., 'qwen2-vl:2b') or null for default
     */
    public function getModel(): ?string
    {
        return $this->model;
    }
}
