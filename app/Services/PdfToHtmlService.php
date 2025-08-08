<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class PdfToHtmlService
{
    /**
     * Convert PDF to structured HTML using the configured PyMuPDF script.
     *
     * @param string $pdfPath Path to the PDF file.
     * @param int|null $documentId The document ID, used for storing associated images.
     * @param string|null $version The specific converter version to use (e.g., 'v11', 'v10').
     * @return array The structured HTML content or an error structure.
     */
    public function convertPdfToStructuredHtml($pdfPath, $documentId = null, $version = null)
    {
        try {
            $pyMuPdfHtml = $this->extractWithPyMuPDF($pdfPath, $documentId, $version);

            if ($pyMuPdfHtml) {
                return [
                    'tables' => [],
                    'text' => '',
                    'images' => [],
                    'styles' => '',
                    'fonts' => [],
                    'full_html' => $pyMuPdfHtml
                ];
            }
        } catch (Exception $e) {
            Log::error('PDF to HTML conversion failed.', [
                'pdf_path' => $pdfPath,
                'error' => $e->getMessage()
            ]);
        }

        // Return empty structure if conversion fails for any reason
        return [
            'tables' => [],
            'text' => '',
            'images' => [],
            'styles' => '',
            'fonts' => [],
            'full_html' => '<div>Erreur: Impossible de convertir le PDF</div>'
        ];
    }

    /**
     * Extract content using a chain of PyMuPDF scripts defined in the config.
     *
     * @param string $pdfPath
     * @param int|null $documentId
     * @param string|null $requestedVersion
     * @return string|null The resulting HTML, or null on failure.
     * @throws Exception
     */
    private function extractWithPyMuPDF($pdfPath, $documentId = null, $requestedVersion = null)
    {
        $imageDir = $this->prepareImageDirectory($documentId);
        
        $convertersToTry = $this->getConverterChain($requestedVersion);

        foreach ($convertersToTry as $versionKey) {
            $scriptPath = Config::get("pdf_converter.converters.{$versionKey}");

            if (!$scriptPath || !File::exists($scriptPath)) {
                Log::warning("Converter script for version '{$versionKey}' not found.", ['path' => $scriptPath]);
                continue;
            }

            $process = new Process([
                'python3',
                $scriptPath,
                $pdfPath,
                $imageDir
            ]);
            
            $process->setTimeout(120); // 2 minutes timeout
            $process->run();

            if ($process->isSuccessful()) {
                $output = $process->getOutput();
                if ($output && (strpos($output, '<style>') !== false || strpos($output, '<div') !== false)) {
                    Log::info("Successfully converted PDF using {$versionKey}.");
                    return $output;
                }
            }
            
            // If the process fails, log the error and try the next converter
            $errorOutput = $process->getErrorOutput();
            Log::error("PyMuPDF extraction failed with {$versionKey}.", [
                'script' => $scriptPath,
                'exit_code' => $process->getExitCode(),
                'error' => substr($errorOutput, 0, 1000) // Log first 1000 chars of error
            ]);
        }

        Log::error('All PDF conversion attempts failed.', ['pdf_path' => $pdfPath]);
        return null;
    }

    /**
     * Prepare and return the directory path for storing images.
     *
     * @param int|null $documentId
     * @return string
     */
    private function prepareImageDirectory($documentId = null)
    {
        if ($documentId) {
            $imageDir = storage_path('app/documents/' . $documentId);
        } else {
            // Create a unique temporary directory if no document ID is provided
            $imageDir = storage_path('app/temp/pdf_images_' . uniqid());
        }

        File::ensureDirectoryExists($imageDir);

        return $imageDir;
    }

    /**
     * Determines the order of converters to try.
     *
     * @param string|null $requestedVersion
     * @return array
     */
    private function getConverterChain($requestedVersion = null)
    {
        $fallbackChain = Config::get('pdf_converter.fallback_chain', []);
        
        if ($requestedVersion && in_array($requestedVersion, $fallbackChain)) {
            // Prioritize the requested version
            $chain = [$requestedVersion];
            // Add the rest of the fallback chain, excluding the requested version
            foreach ($fallbackChain as $version) {
                if ($version !== $requestedVersion) {
                    $chain[] = $version;
                }
            }
            return $chain;
        }

        return $fallbackChain;
    }
}