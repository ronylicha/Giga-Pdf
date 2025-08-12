<?php

namespace App\Services;

use App\Models\Document;
use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PDFPasswordRemovalService
{
    /**
     * Remove password from a PDF document
     *
     * @param Document $document
     * @param string|null $currentPassword
     * @param bool $forceRemove Try to remove password even without knowing it
     * @return Document
     * @throws Exception
     */
    public function removePassword(Document $document, ?string $currentPassword = null, bool $forceRemove = false): Document
    {
        try {
            // Verify the document is a PDF
            if ($document->mime_type !== 'application/pdf') {
                throw new Exception('Le document n\'est pas un fichier PDF');
            }

            $inputPath = Storage::path($document->file_path);

            // Generate output path
            $outputFilename = 'unlocked_' . Str::uuid() . '.pdf';
            $outputPath = 'documents/' . $document->tenant_id . '/' . $outputFilename;
            $fullOutputPath = Storage::path($outputPath);

            // Ensure output directory exists
            $outputDir = dirname($fullOutputPath);
            if (! file_exists($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // If force remove is enabled and no password provided, try to remove protection
            if ($forceRemove && empty($currentPassword)) {
                // Try to remove protection without password using various methods
                if ($this->forceRemoveProtection($inputPath, $fullOutputPath)) {
                    return $this->createUnlockedDocument($document, $outputPath, $outputFilename);
                }

                throw new Exception('Impossible de supprimer la protection du PDF sans mot de passe.');
            }

            // Normal password removal with provided password
            if (! empty($currentPassword)) {
                // Try to remove password using qpdf (most reliable method)
                if ($this->removePasswordWithQpdf($inputPath, $fullOutputPath, $currentPassword)) {
                    return $this->createUnlockedDocument($document, $outputPath, $outputFilename);
                }

                // Fallback to pdftk if qpdf is not available
                if ($this->removePasswordWithPdftk($inputPath, $fullOutputPath, $currentPassword)) {
                    return $this->createUnlockedDocument($document, $outputPath, $outputFilename);
                }

                // Final fallback to PHP libraries
                if ($this->removePasswordWithPhp($inputPath, $fullOutputPath, $currentPassword)) {
                    return $this->createUnlockedDocument($document, $outputPath, $outputFilename);
                }
            }

            throw new Exception('Impossible de supprimer le mot de passe. Vérifiez que le mot de passe est correct ou utilisez la suppression forcée.');

        } catch (Exception $e) {
            throw new Exception('Erreur lors de la suppression du mot de passe : ' . $e->getMessage());
        }
    }

    /**
     * Check if a PDF has a password
     *
     * @param Document $document
     * @return bool
     */
    public function hasPassword(Document $document): bool
    {
        try {
            $path = Storage::path($document->file_path);

            // Try to open with qpdf
            $output = shell_exec("qpdf --show-encryption '$path' 2>&1");
            if (strpos($output, 'encrypted') !== false || strpos($output, 'password') !== false) {
                return true;
            }

            // Try with pdftk
            $output = shell_exec("pdftk '$path' dump_data 2>&1");
            if (strpos($output, 'OWNER PASSWORD REQUIRED') !== false ||
                strpos($output, 'USER PASSWORD REQUIRED') !== false) {
                return true;
            }

            // Try with PHP
            try {
                $pdf = new \TCPDF();
                // TCPDF will throw an exception if password protected
                $pageCount = $pdf->setSourceFile($path);

                return false;
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'password') !== false) {
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Remove password using qpdf
     */
    private function removePasswordWithQpdf(string $inputPath, string $outputPath, string $password): bool
    {
        // Check if qpdf is available
        $qpdfPath = shell_exec('which qpdf');
        if (empty($qpdfPath)) {
            return false;
        }

        // Escape shell arguments
        $inputPath = escapeshellarg($inputPath);
        $outputPath = escapeshellarg($outputPath);
        $password = escapeshellarg($password);

        // Try to decrypt the PDF
        $command = "qpdf --decrypt --password=$password $inputPath $outputPath 2>&1";
        $output = shell_exec($command);

        // Check if the operation was successful
        if (file_exists(str_replace("'", "", $outputPath)) && filesize(str_replace("'", "", $outputPath)) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Remove password using pdftk
     */
    private function removePasswordWithPdftk(string $inputPath, string $outputPath, string $password): bool
    {
        // Check if pdftk is available
        $pdftkPath = shell_exec('which pdftk');
        if (empty($pdftkPath)) {
            return false;
        }

        // Escape shell arguments
        $inputPath = escapeshellarg($inputPath);
        $outputPath = escapeshellarg($outputPath);
        $password = escapeshellarg($password);

        // Try to decrypt the PDF
        $command = "pdftk $inputPath input_pw $password output $outputPath 2>&1";
        $output = shell_exec($command);

        // Check if the operation was successful
        if (file_exists(str_replace("'", "", $outputPath)) && filesize(str_replace("'", "", $outputPath)) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Remove password using PHP libraries
     */
    private function removePasswordWithPhp(string $inputPath, string $outputPath, string $password): bool
    {
        try {
            // Try with TCPDF/FPDI
            $pdf = new \FPDI();
            $pdf->setSourceFile($inputPath, $password);

            $pageCount = $pdf->setSourceFile($inputPath);

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $tplId = $pdf->importPage($pageNo);
                $pdf->AddPage();
                $pdf->useTemplate($tplId);
            }

            $pdf->Output($outputPath, 'F');

            return file_exists($outputPath) && filesize($outputPath) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create a new document record for the unlocked PDF
     */
    private function createUnlockedDocument(Document $original, string $outputPath, string $outputFilename): Document
    {
        $fileSize = Storage::size($outputPath);

        return Document::create([
            'tenant_id' => $original->tenant_id,
            'user_id' => auth()->id() ?? $original->user_id,
            'original_name' => 'Unlocked_' . $original->original_name,
            'file_path' => $outputPath,
            'mime_type' => 'application/pdf',
            'size' => $fileSize,
            'hash' => hash_file('sha256', Storage::path($outputPath)),
            'metadata' => array_merge($original->metadata ?? [], [
                'password_removed' => true,
                'original_document_id' => $original->id,
                'unlocked_at' => now()->toDateTimeString(),
            ]),
        ]);
    }

    /**
     * Force remove PDF protection without password
     * This works only for certain types of protection
     */
    private function forceRemoveProtection(string $inputPath, string $outputPath): bool
    {
        // Method 1: Try qpdf with --decrypt without password (works for owner-only passwords)
        $qpdfPath = shell_exec('which qpdf');
        if (! empty($qpdfPath)) {
            $inputEscaped = escapeshellarg($inputPath);
            $outputEscaped = escapeshellarg($outputPath);

            // Try to decrypt without password (works if only owner password is set)
            $command = "qpdf --decrypt $inputEscaped $outputEscaped 2>&1";
            $output = shell_exec($command);

            if (file_exists($outputPath) && filesize($outputPath) > 0) {
                return true;
            }

            // Try with --force-version to handle older PDFs
            $command = "qpdf --decrypt --force-version=1.4 $inputEscaped $outputEscaped 2>&1";
            $output = shell_exec($command);

            if (file_exists($outputPath) && filesize($outputPath) > 0) {
                return true;
            }
        }

        // Method 2: Try using Ghostscript to rewrite the PDF (removes most protections)
        $gsPath = shell_exec('which gs');
        if (! empty($gsPath)) {
            $inputEscaped = escapeshellarg($inputPath);
            $outputEscaped = escapeshellarg($outputPath);

            // Use Ghostscript to create an unprotected copy
            $command = "gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile=$outputEscaped -c .setpdfwrite -f $inputEscaped 2>&1";
            $output = shell_exec($command);

            if (file_exists($outputPath) && filesize($outputPath) > 0) {
                return true;
            }
        }

        // Method 3: Try using PyPDF2 Python script if available
        $pythonPath = shell_exec('which python3');
        if (! empty($pythonPath)) {
            $scriptPath = resource_path('scripts/python/remove_pdf_protection.py');
            if (file_exists($scriptPath)) {
                $inputEscaped = escapeshellarg($inputPath);
                $outputEscaped = escapeshellarg($outputPath);
                $scriptEscaped = escapeshellarg($scriptPath);

                $command = "python3 $scriptEscaped $inputEscaped $outputEscaped 2>&1";
                $output = shell_exec($command);

                if (file_exists($outputPath) && filesize($outputPath) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate password for a protected PDF
     */
    public function validatePassword(Document $document, string $password): bool
    {
        try {
            $path = Storage::path($document->file_path);
            $tempPath = sys_get_temp_dir() . '/' . Str::uuid() . '.pdf';

            // Try with qpdf
            $result = $this->removePasswordWithQpdf($path, $tempPath, $password);

            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return $result;
        } catch (Exception $e) {
            return false;
        }
    }
}
