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
                    'full_html' => $pyMuPdfHtml,
                ];
            }
        } catch (Exception $e) {
            Log::error('PDF to HTML conversion failed.', [
                'pdf_path' => $pdfPath,
                'error' => $e->getMessage(),
            ]);
        }

        // Return empty structure if conversion fails for any reason
        return [
            'tables' => [],
            'text' => '',
            'images' => [],
            'styles' => '',
            'fonts' => [],
            'full_html' => '<div>Erreur: Impossible de convertir le PDF</div>',
        ];
    }

    /**
     * Convert PDF to self-contained HTML with base64 embedded images.
     *
     * @param string $pdfPath Path to the PDF file.
     * @return string The self-contained HTML content.
     */
    public function convertPdfToSelfContainedHtml($pdfPath)
    {
        try {
            // Use the base64 converter specifically
            $html = $this->extractWithBase64Converter($pdfPath);
            
            if ($html) {
                return $html;
            }
        } catch (Exception $e) {
            Log::error('PDF to self-contained HTML conversion failed.', [
                'pdf_path' => $pdfPath,
                'error' => $e->getMessage(),
            ]);
        }

        // Return error HTML if conversion fails
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Erreur</title></head><body><div>Erreur: Impossible de convertir le PDF</div></body></html>';
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

            if (! $scriptPath || ! File::exists($scriptPath)) {
                Log::warning("Converter script for version '{$versionKey}' not found.", ['path' => $scriptPath]);

                continue;
            }

            $process = new Process([
                'python3',
                $scriptPath,
                $pdfPath,
                $imageDir,
            ]);

            $process->setTimeout(120); // 2 minutes timeout
            $process->run();

            if ($process->isSuccessful()) {
                $output = $process->getOutput();
                if ($output && (strpos($output, '<style>') !== false || strpos($output, '<div') !== false)) {
                    Log::info("Successfully converted PDF using {$versionKey}.");

                    // Fix image paths in HTML - replace relative paths with route-based URLs
                    if ($documentId) {
                        $output = $this->fixImagePaths($output, $documentId, $imageDir);
                    }

                    return $output;
                }
            }

            // If the process fails, log the error and try the next converter
            $errorOutput = $process->getErrorOutput();
            Log::error("PyMuPDF extraction failed with {$versionKey}.", [
                'script' => $scriptPath,
                'exit_code' => $process->getExitCode(),
                'error' => substr($errorOutput, 0, 1000), // Log first 1000 chars of error
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

    /**
     * Fix image paths in HTML to use proper URLs
     *
     * @param string $html The HTML content with relative image paths
     * @param int $documentId The document ID
     * @param string $imageDir The directory where images are stored
     * @return string HTML with fixed image paths
     */
    private function fixImagePaths($html, $documentId, $imageDir)
    {
        // Replace relative image paths with route-based URLs
        return preg_replace_callback(
            '/<img([^>]*?)src="([^"]*)"([^>]*)>/i',
            function ($matches) use ($documentId, $imageDir) {
                $beforeSrc = $matches[1];
                $src = $matches[2];
                $afterSrc = $matches[3];

                // Skip if already a data URI or full URL
                if (strpos($src, 'data:') === 0 || strpos($src, 'http') === 0) {
                    return $matches[0];
                }

                // If src is empty or just a filename, fix it
                if (empty($src) || ! str_contains($src, '/')) {
                    // Extract filename from the full tag if src is empty
                    if (empty($src) && preg_match('/p\d+_(?:img|vec)\d+\.png/', $matches[0], $filenameMatch)) {
                        $src = $filenameMatch[0];
                    }

                    if (! empty($src)) {
                        // Create a route-based URL for the image
                        $newSrc = "/documents/{$documentId}/assets/" . basename($src);
                        Log::debug("Fixed image path", ['original' => $src, 'new' => $newSrc]);

                        return "<img{$beforeSrc}src=\"{$newSrc}\"{$afterSrc}>";
                    }
                }

                return $matches[0];
            },
            $html
        );
    }

    /**
     * Extract content using the base64 converter for self-contained HTML.
     *
     * @param string $pdfPath
     * @return string|null The resulting self-contained HTML, or null on failure.
     */
    private function extractWithBase64Converter($pdfPath)
    {
        $scriptPath = Config::get('pdf_converter.converters.base64');
        
        if (!$scriptPath) {
            // Fallback to direct path if not in config
            $scriptPath = resource_path('scripts/python/pymupdf_converter_base64.py');
        }
        
        if (!File::exists($scriptPath)) {
            Log::error("Base64 converter script not found.", ['path' => $scriptPath]);
            return null;
        }

        $process = new Process([
            'python3',
            $scriptPath,
            $pdfPath,
        ]);

        $process->setTimeout(120); // 2 minutes timeout
        $process->run();

        if ($process->isSuccessful()) {
            $output = $process->getOutput();
            if ($output && strpos($output, '<!DOCTYPE html>') !== false) {
                Log::info("Successfully converted PDF to self-contained HTML with base64 images.");
                
                // Inject CSS fixes in the head
                $cssPath = public_path('css/pdf-editor-fix.css');
                if (file_exists($cssPath)) {
                    $cssContent = '<style>' . file_get_contents($cssPath) . '</style>';
                    $output = str_replace('</head>', $cssContent . '</head>', $output);
                }
                
                // Add targeted CSS fix ONLY for problematic images
                $targetedCss = '<style>
                    /* Only fix images that have 0px dimensions */
                    img[style*="width: 0px"], 
                    img[style*="height: 0px"],
                    img[style*="width:0px"], 
                    img[style*="height:0px"] {
                        width: 100% !important;
                        height: 100% !important;
                        display: block !important;
                        visibility: visible !important;
                        opacity: 1 !important;
                    }
                    
                    /* Ensure all images are at least visible */
                    img {
                        min-width: 1px;
                        min-height: 1px;
                        visibility: visible;
                        opacity: 1;
                    }
                </style>';
                $output = str_replace('</head>', $targetedCss . '</head>', $output);
                
                // Inject the image fix script before closing body tag
                $fixScriptPath = public_path('js/pdf-editor-inline-fix.js');
                if (file_exists($fixScriptPath)) {
                    $fixScript = '
<script>
' . file_get_contents($fixScriptPath) . '
</script>';
                } else {
                    // Fallback inline script if file doesn't exist
                    $fixScript = '
<script>
(function(){
    // Emergency fix for PDF images
    var style = document.createElement("style");
    style.textContent = ".pdf-image,.pdf-vector,img{display:block!important;visibility:visible!important;opacity:1!important}";
    document.head.appendChild(style);
    
    // Fix all images
    setTimeout(function(){
        var imgs = document.querySelectorAll("img");
        imgs.forEach(function(img){
            img.style.display = "block";
            img.style.visibility = "visible";
            img.style.opacity = "1";
        });
        console.log("Fixed " + imgs.length + " images");
    }, 100);
})();
</script>';
                }
                
                // Insert the script before closing body tag
                $output = str_replace('</body>', $fixScript . '</body>', $output);
                
                return $output;
            }
        }

        // Log error if conversion failed
        $errorOutput = $process->getErrorOutput();
        Log::error("Base64 PDF conversion failed.", [
            'script' => $scriptPath,
            'exit_code' => $process->getExitCode(),
            'error' => substr($errorOutput, 0, 1000),
        ]);

        return null;
    }
}
