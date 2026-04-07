<?php

declare(strict_types=1);

namespace App\Service\Contract;

/**
 * Interface for OCR engines that process images and extract text/structured data.
 *
 * This abstraction allows switching between different OCR implementations:
 * - Local models via Ollama (moondream, qwen2-vl, llava-phi3, minicpm-v)
 * - Cloud APIs (OpenAI, Mistral, Google Vision)
 * - Local engines (Tesseract as fallback)
 */
interface OcrEngineInterface
{
    /**
     * Recognize text from one or more images.
     *
     * @param array<string> $imagesBase64 Array of base64-encoded image data
     * @param string|null $model Optional specific model to use (e.g., 'qwen2-vl:2b')
     * @param string|null $jobId Optional job ID (usually analysis ID) for logging
     * @return string JSON-encoded response containing extracted data
     * @throws \RuntimeException If recognition fails
     */
    public function recognize(array $imagesBase64, ?string $model = null, ?string $jobId = null): string;

    /**
     * Check if this engine supports the given MIME type.
     *
     * @param string $mimeType The MIME type of the input file (e.g., 'image/png', 'application/pdf')
     * @return bool True if this engine can process the file type
     */
    public function supports(string $mimeType): bool;

    /**
     * Get the list of available models for this engine.
     *
     * @return array<string> List of model names (e.g., ['moondream:1.8b', 'qwen2-vl:2b'])
     */
    public function getAvailableModels(): array;

    /**
     * Get the default model for this engine.
     *
     * @return string The default model name
     */
    public function getDefaultModel(): string;
    /**
     * Check if this engine supports the given model name.
     *
     * @param string $model The model name
     * @return bool True if this engine can process the model
     */
    public function supportsModel(string $model): bool;
}
