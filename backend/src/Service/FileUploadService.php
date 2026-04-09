<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileUploadService
{
    private string $uploadDir;
    private LoggerInterface $logger;

    // Allowed mime types for medical documents
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/tiff' => 'tiff',
        'image/bmp' => 'bmp',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        ParameterBagInterface $params,
        LoggerInterface $logger
    ) {
        $uploadDir = $params->get('upload_dir');
        if (!is_string($uploadDir)) {
            throw new \InvalidArgumentException('upload_dir must be a string');
        }
        $this->uploadDir = $uploadDir;
        $this->logger = $logger;
    }

    /**
     * Upload a file and return the stored path
     *
     * @return array{filename: string, path: string, fullPath: string, originalName: string, mimeType: string|null, size: int|null}
     */
    public function upload(UploadedFile $file, int $userId): array
    {
        // Get file info BEFORE moving (temp file will be deleted after move)
        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();
        $originalName = $file->getClientOriginalName();
        
        if (!array_key_exists($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException(
                sprintf('Недопустимый тип файла: %s. Разрешены: JPEG, PNG, GIF, WebP, TIFF, BMP, PDF', $mimeType)
            );
        }

        // Generate unique filename
        $extension = self::ALLOWED_MIME_TYPES[$mimeType];
        $filename = sprintf(
            'user_%d_%s.%s',
            $userId,
            uniqid('', true),
            $extension
        );

        // Create user directory
        $userDir = $this->uploadDir . '/user_' . $userId;
        if (!is_dir($userDir)) {
            mkdir($userDir, 0755, true);
        }

        // Full path
        $fullPath = $userDir . '/' . $filename;

        // Move file
        try {
            $file->move($userDir, $filename);
            
            $this->logger->info("File uploaded: {$filename} for user {$userId}");

            return [
                'filename' => $filename,
                'path' => 'user_' . $userId . '/' . $filename,
                'fullPath' => $fullPath,
                'originalName' => $originalName,
                'mimeType' => $mimeType,
                'size' => $fileSize,
            ];
        } catch (\Exception $e) {
            $this->logger->error("File upload failed: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Delete a file
     */
    public function delete(string $path): bool
    {
        $fullPath = $this->uploadDir . '/' . $path;
        
        if (file_exists($fullPath)) {
            $result = unlink($fullPath);
            if ($result) {
                $this->logger->info("File deleted: {$path}");
            }
            return $result;
        }
        
        return false;
    }

    /**
     * Get file information
     *
     * @return array{path: string, fullPath: string, size: int|false, mimeType: string|false, modified: int|false}|null
     */
    public function getFileInfo(string $path): ?array
    {
        $fullPath = $this->uploadDir . '/' . $path;
        
        if (!file_exists($fullPath)) {
            return null;
        }

        return [
            'path' => $path,
            'fullPath' => $fullPath,
            'size' => filesize($fullPath),
            'mimeType' => mime_content_type($fullPath),
            'modified' => filemtime($fullPath),
        ];
    }

    /**
     * Get maximum upload size
     */
    public function getMaxUploadSize(): int
    {
        return (int)ini_get('upload_max_filesize') * 1024 * 1024;
    }
}
