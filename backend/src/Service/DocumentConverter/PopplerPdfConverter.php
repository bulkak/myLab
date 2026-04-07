<?php

declare(strict_types=1);

namespace App\Service\DocumentConverter;

use App\Service\Contract\DocumentConverterInterface;
use Psr\Log\LoggerInterface;

/**
 * Document converter using Poppler (pdftoppm, pdftocairo), optional Ghostscript fallback.
 *
 * Converts PDF files to images for OCR processing and extracts text
 * directly from text-based PDFs when possible.
 */
class PopplerPdfConverter implements DocumentConverterInterface
{
    private const ENGINE_PDFTOPPM = 'pdftoppm';

    private const ENGINE_PDFTOCAIRO = 'pdftocairo';

    private const ENGINE_GHOSTSCRIPT = 'ghostscript';

    private LoggerInterface $logger;
    private int $dpi;
    private string $imageFormat;
    private bool $useCropbox;
    private string $rasterEngine;
    private ?string $fallbackRasterEngine;

    public function __construct(
        LoggerInterface $logger,
        int $dpi = 72,
        string $imageFormat = 'jpeg',
        bool $useCropbox = true,
        string $rasterEngine = self::ENGINE_PDFTOPPM,
        ?string $fallbackRasterEngine = self::ENGINE_PDFTOCAIRO,
    ) {
        $this->logger = $logger;
        $this->dpi = $dpi;
        $this->imageFormat = $imageFormat;
        $this->useCropbox = $useCropbox;
        $this->rasterEngine = $this->normalizeEngineName($rasterEngine);
        if ($fallbackRasterEngine !== null && trim($fallbackRasterEngine) === '') {
            $fallbackRasterEngine = null;
        }
        $this->fallbackRasterEngine = $fallbackRasterEngine !== null
            ? $this->normalizeEngineName($fallbackRasterEngine)
            : null;
        if ($this->fallbackRasterEngine === $this->rasterEngine) {
            $this->fallbackRasterEngine = null;
        }
    }

    /**
     * Convert PDF to array of base64-encoded images.
     *
     * @param string $filePath Path to the PDF file
     * @param string|null $debugDir Optional directory to save debug images
     * @return array<string> Array of base64-encoded images
     * @throws \RuntimeException If conversion fails
     */
    public function convertToImages(string $filePath, ?string $debugDir = null, ?string $jobId = null): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException("File not readable: {$filePath}");
        }

        $this->logger->info('Converting PDF to images', [
            'file' => $filePath,
            'dpi' => $this->dpi,
            'useCropbox' => $this->useCropbox,
            'engine' => $this->rasterEngine,
            'fallback' => $this->fallbackRasterEngine,
        ]);

        $tempDir = sys_get_temp_dir() . '/pdf_' . uniqid();
        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new \RuntimeException("Failed to create temp directory: {$tempDir}");
        }

        try {
            $enginesToTry = array_values(array_unique(array_filter([
                $this->rasterEngine,
                $this->fallbackRasterEngine,
            ])));

            $lastError = '';
            foreach ($enginesToTry as $engine) {
                $this->clearRasterOutputs($tempDir);

                $result = $this->runRasterEngine($engine, $filePath, $tempDir, $lastError);
                if ($result !== null) {
                    $images = $result;

                    $this->logger->info('PDF conversion complete', [
                        'pages' => count($images),
                        'engine' => $engine,
                    ]);

                    $ext = $this->imageFormat === 'jpeg' ? 'jpg' : $this->imageFormat;
                    if ($debugDir && is_dir($debugDir)) {
                        $prefix = $jobId ? "debug_{$jobId}_page_" : 'debug_page_';
                        foreach ($images as $index => $imagePath) {
                            $debugPath = $debugDir . '/' . $prefix . ($index + 1) . '.' . $ext;
                            @copy($imagePath, $debugPath);
                        }
                    }

                    $base64Images = [];
                    foreach ($images as $imagePath) {
                        $base64Images[] = $this->imageToBase64($imagePath);
                    }

                    return $base64Images;
                }
            }

            throw new \RuntimeException(
                'PDF rasterization failed with all configured engines. Last detail: ' . ($lastError ?: 'unknown')
            );
        } finally {
            $this->cleanupDirectory($tempDir);
        }
    }

    /**
     * @return list<string>|null List of image paths, or null to try next engine
     */
    private function runRasterEngine(string $engine, string $filePath, string $tempDir, string &$lastError): ?array
    {
        $outPrefix = $tempDir . '/page';
        $output = [];
        $returnCode = 0;

        try {
            match ($engine) {
                self::ENGINE_PDFTOPPM => $this->execPdftoppm($filePath, $outPrefix, $output, $returnCode),
                self::ENGINE_PDFTOCAIRO => $this->execPdftocairo($filePath, $outPrefix, $output, $returnCode),
                self::ENGINE_GHOSTSCRIPT => $this->execGhostscript($filePath, $outPrefix, $output, $returnCode),
                default => throw new \InvalidArgumentException("Unknown raster engine: {$engine}"),
            };
        } catch (\InvalidArgumentException $e) {
            $lastError = $e->getMessage();
            $this->logger->warning('Raster engine not available', ['engine' => $engine, 'error' => $lastError]);

            return null;
        }

        if ($returnCode !== 0) {
            $lastError = "{$engine} exit {$returnCode}: " . implode("\n", $output);
            $this->logger->warning('Raster command failed', ['engine' => $engine, 'detail' => $lastError]);

            return null;
        }

        $paths = $this->findGeneratedImages($tempDir);
        if ($paths === []) {
            $lastError = "{$engine} produced no image files";
            $this->logger->warning('Raster produced no files', ['engine' => $engine]);

            return null;
        }

        return $paths;
    }

    /**
     * @param list<string> $output
     */
    private function execPdftoppm(string $filePath, string $outPrefix, array &$output, int &$returnCode): void
    {
        $fmt = $this->imageFormat === 'jpg' ? 'jpeg' : $this->imageFormat;
        $crop = $this->useCropbox ? ' -cropbox' : '';
        $command = sprintf(
            'pdftoppm -%s%s -r %d %s %s 2>&1',
            $fmt,
            $crop,
            $this->dpi,
            escapeshellarg($filePath),
            escapeshellarg($outPrefix)
        );
        exec($command, $output, $returnCode);
    }

    /**
     * @param list<string> $output
     */
    private function execPdftocairo(string $filePath, string $outPrefix, array &$output, int &$returnCode): void
    {
        $fmt = match ($this->imageFormat) {
            'jpg', 'jpeg' => '-jpeg',
            'png' => '-png',
            default => throw new \InvalidArgumentException(
                "pdftocairo: unsupported image format {$this->imageFormat}, use png or jpeg"
            ),
        };
        $crop = $this->useCropbox ? ' -cropbox' : '';
        $command = sprintf(
            'pdftocairo %s%s -r %d %s %s 2>&1',
            $fmt,
            $crop,
            $this->dpi,
            escapeshellarg($filePath),
            escapeshellarg($outPrefix)
        );
        exec($command, $output, $returnCode);
    }

    /**
     * @param list<string> $output
     */
    private function execGhostscript(string $filePath, string $outPrefix, array &$output, int &$returnCode): void
    {
        if ($this->imageFormat !== 'png' && $this->imageFormat !== 'jpg' && $this->imageFormat !== 'jpeg') {
            throw new \InvalidArgumentException(
                "ghostscript: unsupported image format {$this->imageFormat}, use png or jpeg"
            );
        }
        $device = ($this->imageFormat === 'png') ? 'png16m' : 'jpeg';
        $pattern = $outPrefix . '-%d.' . (($this->imageFormat === 'png') ? 'png' : 'jpg');
        $cropFlag = $this->useCropbox ? '-dUseCropBox ' : '';
        $command = sprintf(
            'gs -dNOPAUSE -dBATCH -dSAFER -dQUIET %s-sDEVICE=%s -r%d -sOutputFile=%s %s 2>&1',
            $cropFlag,
            $device,
            $this->dpi,
            escapeshellarg($pattern),
            escapeshellarg($filePath)
        );
        exec($command, $output, $returnCode);
    }

    /**
     * @return list<string>
     */
    private function findGeneratedImages(string $tempDir): array
    {
        $ext = $this->imageFormat === 'jpeg' ? 'jpg' : $this->imageFormat;
        $pattern = $tempDir . '/page-*.' . $ext;
        $images = glob($pattern) ?: [];
        natsort($images);

        return array_values($images);
    }

    private function clearRasterOutputs(string $tempDir): void
    {
        foreach (glob($tempDir . '/page-*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function normalizeEngineName(string $engine): string
    {
        $e = strtolower(trim($engine));
        if ($e === 'gs') {
            return self::ENGINE_GHOSTSCRIPT;
        }

        return match ($e) {
            'pdftoppm', self::ENGINE_PDFTOPPM => self::ENGINE_PDFTOPPM,
            'pdftocairo', self::ENGINE_PDFTOCAIRO => self::ENGINE_PDFTOCAIRO,
            'ghostscript', self::ENGINE_GHOSTSCRIPT => self::ENGINE_GHOSTSCRIPT,
            default => throw new \InvalidArgumentException("Invalid pdf raster engine: {$engine}"),
        };
    }

    public function supports(string $filePath): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return $extension === 'pdf';
    }

    public function getSupportedMimeType(): string
    {
        return 'application/pdf';
    }

    public function extractText(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $tempOutput = tempnam(sys_get_temp_dir(), 'pdf_text_');

        try {
            $command = sprintf(
                'pdftotext %s %s 2>&1',
                escapeshellarg($filePath),
                escapeshellarg($tempOutput)
            );

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                $this->logger->debug('pdftotext failed, probably scanned PDF', [
                    'file' => $filePath,
                    'output' => $output,
                ]);

                return null;
            }

            $result = file_get_contents($tempOutput);

            $result = trim((string) $result);

            if ($result === '' || strlen($result) < 10) {
                return null;
            }

            $this->logger->info('Extracted text from PDF', [
                'file' => $filePath,
                'text_length' => strlen($result),
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract text from PDF', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            if (file_exists($tempOutput)) {
                @unlink($tempOutput);
            }
        }
    }

    private function imageToBase64(string $imagePath): string
    {
        $content = file_get_contents($imagePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read image: {$imagePath}");
        }

        return base64_encode($content);
    }

    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->cleanupDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
