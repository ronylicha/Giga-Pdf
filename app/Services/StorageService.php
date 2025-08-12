<?php

namespace App\Services;

use App\Models\Document;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StorageService
{
    /**
     * Store uploaded file
     */
    public function storeUpload(UploadedFile $file, Document $document): string
    {
        $tenant = auth()->user()->tenant;

        // Generate unique path
        $path = $this->generatePath($document);

        // Store the file
        $storedPath = $file->storeAs(
            $path,
            $this->generateFileName($file),
            'local'
        );

        if (! $storedPath) {
            throw new Exception('Failed to store file');
        }

        return $storedPath;
    }

    /**
     * Store file from path
     */
    public function store(string $sourcePath, Document $document): string
    {
        $tenant = auth()->user()->tenant;

        // Generate unique path
        $path = $this->generatePath($document);
        $fileName = $this->generateFileNameFromPath($sourcePath);
        $destinationPath = $path . '/' . $fileName;

        // Ensure directory exists
        Storage::disk('local')->makeDirectory($path);

        // Move file to storage
        if (! Storage::disk('local')->put($destinationPath, file_get_contents($sourcePath))) {
            throw new Exception('Failed to store file');
        }

        return $destinationPath;
    }

    /**
     * Get file path
     */
    public function getPath(Document $document): string
    {
        $path = Storage::disk('local')->path($document->stored_name);

        if (! file_exists($path)) {
            throw new Exception('File not found: ' . $document->stored_name);
        }

        return $path;
    }

    /**
     * Get temporary file path
     */
    public function getTempPath(string $extension = ''): string
    {
        $tempDir = storage_path('app/temp');

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $fileName = Str::random(32);
        if ($extension) {
            $fileName .= '.' . ltrim($extension, '.');
        }

        return $tempDir . '/' . $fileName;
    }

    /**
     * Delete document file
     */
    public function delete(Document $document): bool
    {
        try {
            // Delete main file
            if ($document->stored_name && Storage::disk('local')->exists($document->stored_name)) {
                Storage::disk('local')->delete($document->stored_name);
            }

            // Delete thumbnail
            if ($document->thumbnail_path && Storage::disk('local')->exists($document->thumbnail_path)) {
                Storage::disk('local')->delete($document->thumbnail_path);
            }

            return true;
        } catch (Exception $e) {
            \Log::error('Failed to delete document files', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Create backup of document
     */
    public function createBackup(Document $document): string
    {
        $sourcePath = $this->getPath($document);
        $backupDir = storage_path('app/backups/' . $document->tenant_id);

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupPath = $backupDir . '/' . $document->id . '_' . time() . '_' . basename($document->stored_name);

        if (! copy($sourcePath, $backupPath)) {
            throw new Exception('Failed to create backup');
        }

        return $backupPath;
    }

    /**
     * Restore from backup
     */
    public function restoreFromBackup(Document $document, string $backupPath): bool
    {
        if (! file_exists($backupPath)) {
            throw new Exception('Backup file not found');
        }

        $destinationPath = Storage::disk('local')->path($document->stored_name);

        // Ensure directory exists
        $dir = dirname($destinationPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return copy($backupPath, $destinationPath);
    }

    /**
     * Replace document file
     */
    public function replace(Document $document, string $newFilePath): bool
    {
        $destinationPath = Storage::disk('local')->path($document->stored_name);

        // Ensure directory exists
        $dir = dirname($destinationPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Replace file
        if (! copy($newFilePath, $destinationPath)) {
            throw new Exception('Failed to replace file');
        }

        return true;
    }

    /**
     * Get file size
     */
    public function getSize(Document $document): int
    {
        return Storage::disk('local')->size($document->stored_name);
    }

    /**
     * Get file mime type
     */
    public function getMimeType(Document $document): string
    {
        return Storage::disk('local')->mimeType($document->stored_name);
    }

    /**
     * Generate storage path for document
     */
    protected function generatePath(Document $document): string
    {
        $tenant = $document->tenant;
        $date = now();

        return sprintf(
            'tenants/%s/%d/%02d/%02d',
            $tenant->slug,
            $date->year,
            $date->month,
            $date->day
        );
    }

    /**
     * Generate unique file name
     */
    protected function generateFileName(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $name = Str::random(32);

        if ($extension) {
            $name .= '.' . $extension;
        }

        return $name;
    }

    /**
     * Generate file name from path
     */
    protected function generateFileNameFromPath(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $name = Str::random(32);

        if ($extension) {
            $name .= '.' . $extension;
        }

        return $name;
    }

    /**
     * Clean temporary files
     */
    public function cleanTempFiles(int $olderThanMinutes = 60): int
    {
        $tempDir = storage_path('app/temp');
        $count = 0;

        if (! is_dir($tempDir)) {
            return 0;
        }

        $files = glob($tempDir . '/*');
        $threshold = time() - ($olderThanMinutes * 60);

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $threshold) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Get storage statistics for tenant
     */
    public function getTenantStorageStats(int $tenantId): array
    {
        $basePath = storage_path('app/tenants/' . $tenantId);

        if (! is_dir($basePath)) {
            return [
                'total_size' => 0,
                'file_count' => 0,
                'directory_count' => 0,
            ];
        }

        $size = 0;
        $fileCount = 0;
        $dirCount = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
                $fileCount++;
            } elseif ($file->isDir() && ! in_array($file->getBasename(), ['.', '..'])) {
                $dirCount++;
            }
        }

        return [
            'total_size' => $size,
            'file_count' => $fileCount,
            'directory_count' => $dirCount,
        ];
    }
}
