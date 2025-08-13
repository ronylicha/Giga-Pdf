<?php

namespace App\Services;

use App\Exceptions\ConversionFailedException;
use App\Exceptions\InvalidDocumentException;
use App\Models\Conversion;
use App\Models\Document;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ConversionService
{
    /**
     * Main conversion method used by ProcessConversion job
     * Uses LibreOffice for all conversions to ensure format preservation
     */
    public function convert(string $inputPath, string $fromFormat, string $toFormat, array $options = []): string
    {
        // Use local storage for output directory
        $outputDir = storage_path('app/conversions');
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputPath = $outputDir . '/' . uniqid() . '.' . $toFormat;

        try {
            Log::info('Starting conversion', [
                'from' => $fromFormat,
                'to' => $toFormat,
                'input' => $inputPath,
            ]);

            // Use LibreOffice for all conversions
            $this->convertWithLibreOffice($inputPath, $outputPath, $fromFormat, $toFormat, $options);

            if (! file_exists($outputPath) || filesize($outputPath) === 0) {
                throw new ConversionFailedException("La conversion a échoué - fichier de sortie vide ou inexistant");
            }

            Log::info('Conversion successful', [
                'output' => $outputPath,
                'size' => filesize($outputPath),
            ]);

            return $outputPath;

        } catch (Exception $e) {
            Log::error('Conversion failed', [
                'input' => $inputPath,
                'output' => $outputPath,
                'from' => $fromFormat,
                'to' => $toFormat,
                'error' => $e->getMessage(),
            ]);

            throw new ConversionFailedException("Conversion échouée: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Convert using LibreOffice with proper filters for format preservation
     */
    protected function convertWithLibreOffice(string $inputPath, string $outputPath, string $fromFormat, string $toFormat, array $options = []): void
    {
        // Use local temp directory within storage
        $tempDir = storage_path('app/libreoffice/temp/conversion_' . uniqid());
        mkdir($tempDir, 0755, true);

        try {
            // Determine the appropriate filter for the conversion
            $filter = $this->getLibreOfficeFilter($fromFormat, $toFormat);

            // Build the LibreOffice command
            $command = $this->buildLibreOfficeCommand($inputPath, $tempDir, $toFormat, $filter);

            Log::info('Executing LibreOffice command', [
                'command' => $command,
            ]);

            exec($command, $output, $returnCode);
            
            // Log the output for debugging
            Log::info('LibreOffice output', [
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
                'tempDir' => $tempDir,
            ]);

            if ($returnCode !== 0) {
                throw new ConversionFailedException(
                    "LibreOffice conversion failed. Output: " . implode("\n", $output)
                );
            }

            // Find the converted file
            $convertedFile = $this->findConvertedFile($tempDir, $inputPath, $toFormat);

            if (! $convertedFile) {
                // Log directory contents for debugging
                $files = glob($tempDir . '/*');
                Log::error('Converted file not found', [
                    'tempDir' => $tempDir,
                    'files' => $files,
                    'expectedFormat' => $toFormat,
                ]);
                throw new ConversionFailedException("Fichier converti non trouvé dans le répertoire temporaire");
            }

            // Move to final location
            if (! rename($convertedFile, $outputPath)) {
                throw new ConversionFailedException("Impossible de déplacer le fichier converti");
            }

        } finally {
            $this->cleanupDirectory($tempDir);
        }
    }

    /**
     * Build LibreOffice command with appropriate parameters
     */
    protected function buildLibreOfficeCommand(string $inputPath, string $outputDir, string $toFormat, ?array $filter): string
    {
        // Use local directories for LibreOffice to avoid permission issues
        $userProfile = storage_path('app/libreoffice/config');
        $cacheDir = storage_path('app/libreoffice/cache');
        
        // Ensure directories exist
        if (!is_dir($userProfile)) {
            mkdir($userProfile, 0775, true);
        }
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        
        $baseCommand = sprintf(
            'env HOME=%s libreoffice --headless --invisible --nodefault --nolockcheck --nologo --norestore -env:UserInstallation=file://%s',
            escapeshellarg($cacheDir),
            $userProfile
        );

        if ($filter && $filter['input']) {
            // With input filter
            $convertTo = $filter['output'] ? sprintf('%s:%s', $toFormat, $filter['output']) : $toFormat;
            return sprintf(
                '%s --infilter=%s --convert-to %s --outdir %s %s 2>&1',
                $baseCommand,
                escapeshellarg($filter['input']),
                escapeshellarg($convertTo),
                escapeshellarg($outputDir),
                escapeshellarg($inputPath)
            );
        } elseif ($filter && $filter['output']) {
            // With output filter only
            return sprintf(
                '%s --convert-to %s:%s --outdir %s %s 2>&1',
                $baseCommand,
                $toFormat,
                escapeshellarg($filter['output']),
                escapeshellarg($outputDir),
                escapeshellarg($inputPath)
            );
        } else {
            // No filter
            return sprintf(
                '%s --convert-to %s --outdir %s %s 2>&1',
                $baseCommand,
                $toFormat,
                escapeshellarg($outputDir),
                escapeshellarg($inputPath)
            );
        }
    }

    /**
     * Get the appropriate LibreOffice filter for conversion
     */
    protected function getLibreOfficeFilter(string $fromFormat, string $toFormat): ?array
    {
        $filters = [
            // PDF imports with format preservation
            'pdf_to_docx' => [
                'input' => 'writer_pdf_import',
                'output' => 'MS Word 2007 XML',
            ],
            'pdf_to_doc' => [
                'input' => 'writer_pdf_import',
                'output' => 'MS Word 97',
            ],
            'pdf_to_xlsx' => [
                'input' => 'calc_pdf_import',
                'output' => 'Calc MS Excel 2007 XML',
            ],
            'pdf_to_xls' => [
                'input' => 'calc_pdf_import',
                'output' => 'MS Excel 97',
            ],
            'pdf_to_pptx' => [
                'input' => 'impress_pdf_import',
                'output' => 'Impress MS PowerPoint 2007 XML',
            ],
            'pdf_to_ppt' => [
                'input' => 'impress_pdf_import',
                'output' => 'MS PowerPoint 97',
            ],
            'pdf_to_odt' => [
                'input' => 'writer_pdf_import',
                'output' => 'writer8',
            ],
            'pdf_to_ods' => [
                'input' => 'calc_pdf_import',
                'output' => 'calc8',
            ],
            'pdf_to_odp' => [
                'input' => 'impress_pdf_import',
                'output' => 'impress8',
            ],

            // HTML conversions
            'html_to_pdf' => [
                'input' => 'HTML',
                'output' => 'writer_pdf_Export',
            ],
            'pdf_to_html' => [
                'input' => 'writer_pdf_import',
                'output' => 'HTML',
            ],

            // Text conversions
            'txt_to_pdf' => [
                'input' => 'Text',
                'output' => 'writer_pdf_Export',
            ],
            'pdf_to_txt' => [
                'input' => 'writer_pdf_import',
                'output' => 'Text',
            ],

            // Image to PDF
            'jpg_to_pdf' => [
                'input' => null,
                'output' => 'writer_pdf_Export',
            ],
            'png_to_pdf' => [
                'input' => null,
                'output' => 'writer_pdf_Export',
            ],

            // Office format conversions
            'docx_to_pdf' => [
                'input' => null,
                'output' => 'writer_pdf_Export',
            ],
            'xlsx_to_pdf' => [
                'input' => null,
                'output' => 'calc_pdf_Export',
            ],
            'pptx_to_pdf' => [
                'input' => null,
                'output' => 'impress_pdf_Export',
            ],

            // Inter-office conversions
            'docx_to_odt' => [
                'input' => null,
                'output' => 'writer8',
            ],
            'xlsx_to_ods' => [
                'input' => null,
                'output' => 'calc8',
            ],
            'pptx_to_odp' => [
                'input' => null,
                'output' => 'impress8',
            ],
            'odt_to_docx' => [
                'input' => null,
                'output' => 'MS Word 2007 XML',
            ],
            'ods_to_xlsx' => [
                'input' => null,
                'output' => 'Calc MS Excel 2007 XML',
            ],
            'odp_to_pptx' => [
                'input' => null,
                'output' => 'Impress MS PowerPoint 2007 XML',
            ],
        ];

        $key = $fromFormat . '_to_' . $toFormat;

        return $filters[$key] ?? null;
    }

    /**
     * Find the converted file in the temporary directory
     */
    protected function findConvertedFile(string $tempDir, string $inputPath, string $toFormat): ?string
    {
        $baseName = pathinfo($inputPath, PATHINFO_FILENAME);
        $expectedFile = $tempDir . '/' . $baseName . '.' . $toFormat;

        if (file_exists($expectedFile)) {
            return $expectedFile;
        }

        // Search for any file with the target extension
        $files = glob($tempDir . '/*.' . $toFormat);
        if (! empty($files)) {
            return $files[0];
        }

        return null;
    }

    /**
     * Clean up temporary directory
     */
    protected function cleanupDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }

    /**
     * Convert document to PDF (high-level method for models)
     */
    public function convertToPDF(Document $document, array $options = []): Document
    {
        $inputPath = Storage::path($document->stored_name);
        $outputPath = sys_get_temp_dir() . '/' . uniqid() . '.pdf';

        try {
            // Get format from mime type
            $fromFormat = $this->getFormatFromMimeType($document->mime_type);

            // Perform conversion
            $this->convert($inputPath, $fromFormat, 'pdf', $options);

            // Extract metadata
            $metadata = $this->extractPDFMetadata($outputPath);

            // Create new document
            $pdfDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '.pdf',
                'stored_name' => '',
                'mime_type' => 'application/pdf',
                'size' => filesize($outputPath),
                'hash' => hash_file('sha256', $outputPath),
                'metadata' => $metadata,
                'parent_id' => $document->id,
                'page_count' => $metadata['pages'] ?? null,
            ]);

            // Move to permanent storage
            $directory = 'documents/' . $pdfDocument->tenant_id . '/' . date('Y/m');
            $filename = Str::uuid() . '.pdf';
            $storedPath = $directory . '/' . $filename;

            Storage::put($storedPath, file_get_contents($outputPath));
            $pdfDocument->update(['stored_name' => $storedPath]);

            return $pdfDocument;

        } catch (Exception $e) {
            Log::error('PDF conversion failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            throw new ConversionFailedException(
                "Failed to convert document to PDF: " . $e->getMessage(),
                0,
                $e
            );
        } finally {
            if (isset($outputPath) && file_exists($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    /**
     * Convert PDF to another format (high-level method for models)
     */
    public function convertFromPDF(Document $document, string $targetFormat, array $options = []): Document
    {
        if ($document->mime_type !== 'application/pdf') {
            throw new InvalidDocumentException("Document is not a PDF");
        }

        $inputPath = Storage::path($document->stored_name);
        $outputPath = sys_get_temp_dir() . '/' . uniqid() . '.' . $targetFormat;

        try {
            // Perform conversion
            $this->convert($inputPath, 'pdf', $targetFormat, $options);

            // Get mime type for target format
            $mimeType = $this->getMimeTypeForFormat($targetFormat);

            // Create new document
            $convertedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '.' . $targetFormat,
                'stored_name' => '',
                'mime_type' => $mimeType,
                'size' => filesize($outputPath),
                'hash' => hash_file('sha256', $outputPath),
                'parent_id' => $document->id,
            ]);

            // Move to permanent storage
            $directory = 'documents/' . $convertedDocument->tenant_id . '/' . date('Y/m');
            $filename = Str::uuid() . '.' . $targetFormat;
            $storedPath = $directory . '/' . $filename;

            Storage::put($storedPath, file_get_contents($outputPath));
            $convertedDocument->update(['stored_name' => $storedPath]);

            return $convertedDocument;

        } catch (Exception $e) {
            Log::error('Conversion from PDF failed', [
                'document_id' => $document->id,
                'target_format' => $targetFormat,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            if (isset($outputPath) && file_exists($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    /**
     * Extract PDF metadata using pdfinfo
     */
    protected function extractPDFMetadata(string $pdfPath): array
    {
        $metadata = [];

        try {
            $command = sprintf('pdfinfo %s 2>&1', escapeshellarg($pdfPath));
            exec($command, $output);

            foreach ($output as $line) {
                if (preg_match('/^([^:]+):\s+(.*)$/', $line, $matches)) {
                    $key = str_replace(' ', '_', strtolower($matches[1]));
                    $metadata[$key] = trim($matches[2]);
                }
            }

            if (isset($metadata['pages'])) {
                $metadata['pages'] = (int) $metadata['pages'];
            }

        } catch (Exception $e) {
            Log::warning('Failed to extract PDF metadata', [
                'file' => $pdfPath,
                'error' => $e->getMessage(),
            ]);
        }

        return $metadata;
    }

    /**
     * Get format from mime type
     */
    protected function getFormatFromMimeType(string $mimeType): string
    {
        $map = [
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.oasis.opendocument.text' => 'odt',
            'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
            'application/vnd.oasis.opendocument.presentation' => 'odp',
            'application/rtf' => 'rtf',
            'text/rtf' => 'rtf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/webp' => 'webp',
            'text/html' => 'html',
            'text/plain' => 'txt',
            'text/markdown' => 'md',
            'text/csv' => 'csv',
            'application/json' => 'json',
            'application/xml' => 'xml',
            'text/xml' => 'xml',
        ];

        return $map[$mimeType] ?? 'unknown';
    }

    /**
     * Get mime type for format
     */
    protected function getMimeTypeForFormat(string $format): string
    {
        $map = [
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'ppt' => 'application/vnd.ms-powerpoint',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            'rtf' => 'application/rtf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
            'webp' => 'image/webp',
            'html' => 'text/html',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',
        ];

        return $map[$format] ?? 'application/octet-stream';
    }

    /**
     * Check if conversion is supported
     */
    public function isConversionSupported(string $fromFormat, string $toFormat): bool
    {
        // LibreOffice supports a wide range of conversions
        $supportedFormats = [
            'pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt',
            'odt', 'ods', 'odp', 'rtf', 'html', 'txt', 'csv',
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp',
        ];

        return in_array($fromFormat, $supportedFormats) && in_array($toFormat, $supportedFormats);
    }

    /**
     * Get supported output formats for a given input format
     */
    public function getSupportedOutputFormats(string $inputFormat): array
    {
        $formatGroups = [
            'document' => ['pdf', 'docx', 'doc', 'odt', 'rtf', 'html', 'txt'],
            'spreadsheet' => ['pdf', 'xlsx', 'xls', 'ods', 'csv', 'html'],
            'presentation' => ['pdf', 'pptx', 'ppt', 'odp', 'html'],
            'image' => ['pdf', 'jpg', 'png', 'gif', 'bmp', 'tiff', 'webp'],
            'web' => ['pdf', 'docx', 'odt', 'txt'],
        ];

        $inputGroups = [
            'pdf' => array_merge($formatGroups['document'], $formatGroups['spreadsheet'], $formatGroups['presentation'], $formatGroups['image']),
            'docx' => $formatGroups['document'],
            'doc' => $formatGroups['document'],
            'odt' => $formatGroups['document'],
            'rtf' => $formatGroups['document'],
            'xlsx' => $formatGroups['spreadsheet'],
            'xls' => $formatGroups['spreadsheet'],
            'ods' => $formatGroups['spreadsheet'],
            'csv' => $formatGroups['spreadsheet'],
            'pptx' => $formatGroups['presentation'],
            'ppt' => $formatGroups['presentation'],
            'odp' => $formatGroups['presentation'],
            'jpg' => $formatGroups['image'],
            'jpeg' => $formatGroups['image'],
            'png' => $formatGroups['image'],
            'gif' => $formatGroups['image'],
            'bmp' => $formatGroups['image'],
            'tiff' => $formatGroups['image'],
            'webp' => $formatGroups['image'],
            'html' => $formatGroups['web'],
            'txt' => ['pdf', 'docx', 'odt', 'html'],
        ];

        $formats = $inputGroups[$inputFormat] ?? ['pdf'];

        // Remove the input format from output formats
        return array_values(array_diff($formats, [$inputFormat]));
    }
}
