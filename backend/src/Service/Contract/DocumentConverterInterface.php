<?php

declare(strict_types=1);

namespace App\Service\Contract;

/**
 * Interface for document converters that transform various file formats
 * into an array of images suitable for OCR processing.
 *
 * This abstraction handles:
 * - PDF to image conversion
 * - Image format normalization
 * - Multi-page document handling
 */
interface DocumentConverterInterface
{
    /**
     * Convert a document to an array of base64-encoded images.
     *
     * For single-page documents, returns an array with one element.
     * For multi-page documents (e.g., PDF), returns an image per page.
     *
     * @param string $filePath Path to the document file
     * @return array<string> Array of base64-encoded image data
     * @throws \RuntimeException If conversion fails
     */
    public function convertToImages(string $filePath, ?string $debugDir = null, ?string $jobId = null): array;

    /**
     * Check if this converter supports the given file.
     *
     * @param string $filePath Path to the file
     * @return bool True if this converter can handle the file
     */
    public function supports(string $filePath): bool;

    /**
     * Extract text directly from the document if possible (for text-based PDFs).
     *
     * This is an optimization - if the document contains embedded text,
     * return it directly without OCR. Otherwise, return null to fall back to OCR.
     *
     * @param string $filePath Path to the document file
     * @return string|null Extracted text if available, null otherwise
     */
    public function extractText(string $filePath): ?string;
}
