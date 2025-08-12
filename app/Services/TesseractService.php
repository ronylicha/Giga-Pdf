<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class TesseractService
{
    protected $tesseractPath;
    protected $languages;
    protected $timeout;

    public function __construct()
    {
        $this->tesseractPath = config('services.tesseract.path', '/usr/bin/tesseract');
        $this->languages = config('services.tesseract.languages', ['eng', 'fra']);
        $this->timeout = config('services.tesseract.timeout', 60);
    }

    /**
     * Extract text from image using OCR
     */
    public function extractText(string $imagePath, string $language = 'eng'): string
    {
        if (! file_exists($imagePath)) {
            throw new Exception("Image file not found: {$imagePath}");
        }

        if (! $this->isAvailable()) {
            throw new Exception("Tesseract OCR is not available");
        }

        // Validate language
        if (! in_array($language, $this->languages)) {
            $language = 'eng';
        }

        // Create temporary output file
        $outputFile = tempnam(sys_get_temp_dir(), 'ocr_');

        try {
            // Build command
            $command = sprintf(
                '%s %s %s -l %s --oem 3 --psm 3 2>&1',
                escapeshellcmd($this->tesseractPath),
                escapeshellarg($imagePath),
                escapeshellarg($outputFile),
                escapeshellarg($language)
            );

            // Add timeout
            if ($this->timeout > 0) {
                $command = sprintf('timeout %d %s', $this->timeout, $command);
            }

            // Execute OCR
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                Log::error('Tesseract OCR failed', [
                    'command' => $command,
                    'output' => $output,
                    'return_code' => $returnCode,
                ]);

                throw new Exception('OCR extraction failed: ' . implode("\n", $output));
            }

            // Read extracted text
            $textFile = $outputFile . '.txt';
            if (! file_exists($textFile)) {
                throw new Exception('OCR output file not found');
            }

            $text = file_get_contents($textFile);

            // Clean up temporary file
            @unlink($textFile);

            return $text;

        } finally {
            // Clean up
            @unlink($outputFile);
            if (isset($textFile)) {
                @unlink($textFile);
            }
        }
    }

    /**
     * Extract text from PDF using OCR
     */
    public function extractTextFromPDF(string $pdfPath, string $language = 'eng'): string
    {
        if (! file_exists($pdfPath)) {
            throw new Exception("PDF file not found: {$pdfPath}");
        }

        // Convert PDF pages to images first
        $imagickService = app(ImagickService::class);
        $tempDir = sys_get_temp_dir() . '/ocr_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            // Extract pages as images
            $pages = $imagickService->extractPDFPages($pdfPath, $tempDir, 'png', 300);

            $fullText = '';

            // OCR each page
            foreach ($pages as $page) {
                $pageText = $this->extractText($page['path'], $language);
                $fullText .= "--- Page {$page['page']} ---\n";
                $fullText .= $pageText . "\n\n";

                // Clean up page image
                @unlink($page['path']);
            }

            return $fullText;

        } finally {
            // Clean up temp directory
            $this->cleanupDirectory($tempDir);
        }
    }

    /**
     * Extract text with multiple languages
     */
    public function extractTextMultiLang(string $imagePath, array $languages = ['eng', 'fra']): string
    {
        if (! file_exists($imagePath)) {
            throw new Exception("Image file not found: {$imagePath}");
        }

        // Filter valid languages
        $validLanguages = array_intersect($languages, $this->languages);

        if (empty($validLanguages)) {
            $validLanguages = ['eng'];
        }

        // Join languages for Tesseract
        $langString = implode('+', $validLanguages);

        return $this->extractText($imagePath, $langString);
    }

    /**
     * Create searchable PDF from image
     */
    public function createSearchablePDF(string $imagePath, string $outputPath, string $language = 'eng'): bool
    {
        if (! file_exists($imagePath)) {
            throw new Exception("Image file not found: {$imagePath}");
        }

        if (! $this->isAvailable()) {
            throw new Exception("Tesseract OCR is not available");
        }

        // Build command for PDF output
        $command = sprintf(
            '%s %s %s -l %s --oem 3 --psm 3 pdf 2>&1',
            escapeshellcmd($this->tesseractPath),
            escapeshellarg($imagePath),
            escapeshellarg(str_replace('.pdf', '', $outputPath)),
            escapeshellarg($language)
        );

        // Add timeout
        if ($this->timeout > 0) {
            $command = sprintf('timeout %d %s', $this->timeout, $command);
        }

        // Execute OCR
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error('Tesseract searchable PDF creation failed', [
                'command' => $command,
                'output' => $output,
                'return_code' => $returnCode,
            ]);

            throw new Exception('Searchable PDF creation failed: ' . implode("\n", $output));
        }

        return file_exists($outputPath);
    }

    /**
     * Preprocess image for better OCR results
     */
    public function preprocessImage(string $inputPath, string $outputPath): bool
    {
        try {
            $imagick = new \Imagick($inputPath);

            // Convert to grayscale
            $imagick->setImageType(\Imagick::IMGTYPE_GRAYSCALE);

            // Increase contrast
            $imagick->contrastImage(1);

            // Remove noise
            $imagick->despeckleImage();

            // Sharpen
            $imagick->sharpenImage(0, 1);

            // Threshold to black and white
            $imagick->thresholdImage(0.5 * $imagick->getQuantumRange()['quantumRangeLong']);

            // Save preprocessed image
            $imagick->writeImage($outputPath);

            $imagick->clear();
            $imagick->destroy();

            return true;

        } catch (Exception $e) {
            Log::error('Image preprocessing failed', [
                'input' => $inputPath,
                'output' => $outputPath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get available OCR languages
     */
    public function getAvailableLanguages(): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $command = sprintf('%s --list-langs 2>&1', escapeshellcmd($this->tesseractPath));
        $output = shell_exec($command);

        if (! $output) {
            return [];
        }

        $lines = explode("\n", $output);
        $languages = [];

        $startParsing = false;
        foreach ($lines as $line) {
            if (strpos($line, 'List of available languages') !== false) {
                $startParsing = true;

                continue;
            }

            if ($startParsing && ! empty(trim($line))) {
                $languages[] = trim($line);
            }
        }

        return $languages;
    }

    /**
     * Check if Tesseract is available
     */
    public function isAvailable(): bool
    {
        if (! file_exists($this->tesseractPath)) {
            // Try to find tesseract
            $output = shell_exec('which tesseract 2>/dev/null');
            if ($output) {
                $this->tesseractPath = trim($output);

                return true;
            }

            return false;
        }

        return is_executable($this->tesseractPath);
    }

    /**
     * Get Tesseract version
     */
    public function getVersion(): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $command = sprintf('%s --version 2>&1', escapeshellcmd($this->tesseractPath));
        $output = shell_exec($command);

        if ($output && preg_match('/tesseract\s+([\d\.]+)/i', $output, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Clean up directory
     */
    protected function cleanupDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanupDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
