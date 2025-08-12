<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF;
use Exception;

class PDFSignatureService
{
    /**
     * Add digital signature to PDF
     */
    public function signDocument(
        Document $document,
        string $certificatePath,
        string $privateKeyPath,
        string $password,
        array $options = []
    ): Document {
        try {
            $sourcePath = Storage::path($document->stored_name);
            
            // Generate output filename
            $filename = Str::slug(pathinfo($document->original_name, PATHINFO_FILENAME)) 
                . '_signed_' . time() . '.pdf';
            $outputPath = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullOutputPath = Storage::path($outputPath);
            
            // Ensure directory exists
            $dir = dirname($fullOutputPath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            // Create new PDF with TCPDF
            $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
            
            // Set document information
            $pdf->SetCreator('Giga-PDF');
            $pdf->SetAuthor($options['author'] ?? 'Giga-PDF User');
            $pdf->SetTitle($document->original_name);
            $pdf->SetSubject('Digitally Signed Document');
            
            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Set certificate file
            $certificate = 'file://' . $certificatePath;
            $privateKey = 'file://' . $privateKeyPath;
            
            // Set additional information
            $info = [
                'Name' => $options['signer_name'] ?? 'Document Signer',
                'Location' => $options['location'] ?? 'Unknown',
                'Reason' => $options['reason'] ?? 'Document Signature',
                'ContactInfo' => $options['contact'] ?? 'https://giga-pdf.com',
            ];
            
            // Set document signature
            $pdf->setSignature(
                $certificate,
                $privateKey,
                $password,
                '',
                2,
                $info
            );
            
            // Import existing PDF pages
            $pageCount = $pdf->setSourceFile($sourcePath);
            
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                
                // Add a page with same orientation and size
                $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                
                // Use the imported page
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);
                
                // Add visible signature on the last page if requested
                if ($pageNo === $pageCount && ($options['visible_signature'] ?? false)) {
                    $this->addVisibleSignature($pdf, $options);
                }
            }
            
            // Add signature appearance if requested
            if ($options['signature_appearance'] ?? false) {
                $pdf->setSignatureAppearance(
                    $options['appearance_x'] ?? 30,
                    $options['appearance_y'] ?? 230,
                    $options['appearance_width'] ?? 60,
                    $options['appearance_height'] ?? 30,
                    $options['appearance_page'] ?? $pageCount
                );
            }
            
            // Output the signed PDF
            $pdf->Output($fullOutputPath, 'F');
            
            // Create new document record
            $signedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => str_replace('.pdf', '_signed.pdf', $document->original_name),
                'stored_name' => $outputPath,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullOutputPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullOutputPath),
                'metadata' => array_merge($document->metadata ?? [], [
                    'type' => 'signed',
                    'source_document' => $document->id,
                    'signer' => $options['signer_name'] ?? 'Unknown',
                    'signed_at' => now()->toIso8601String(),
                    'signature_reason' => $options['reason'] ?? 'Document Signature',
                    'signature_location' => $options['location'] ?? 'Unknown',
                ]),
            ]);
            
            return $signedDocument;
            
        } catch (Exception $e) {
            throw new Exception('Error signing PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Add visible signature to PDF
     */
    private function addVisibleSignature(TCPDF $pdf, array $options): void
    {
        $x = $options['visible_x'] ?? 30;
        $y = $options['visible_y'] ?? 230;
        $width = $options['visible_width'] ?? 60;
        $height = $options['visible_height'] ?? 30;
        
        // Draw signature box
        $pdf->SetLineStyle(['width' => 0.5, 'color' => [0, 0, 0]]);
        $pdf->Rect($x, $y, $width, $height);
        
        // Add signature image if provided
        if (!empty($options['signature_image'])) {
            $pdf->Image(
                $options['signature_image'],
                $x + 2,
                $y + 2,
                $width - 4,
                $height - 12,
                '',
                '',
                '',
                false,
                300,
                '',
                false,
                false,
                0
            );
        }
        
        // Add signature text
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY($x + 2, $y + $height - 10);
        
        $signatureText = sprintf(
            "Digitally signed by %s\nDate: %s",
            $options['signer_name'] ?? 'Unknown',
            now()->format('Y-m-d H:i:s')
        );
        
        $pdf->MultiCell($width - 4, 8, $signatureText, 0, 'C');
    }
    
    /**
     * Verify PDF signature
     */
    public function verifySignature(Document $document): array
    {
        try {
            $pdfPath = Storage::path($document->stored_name);
            
            // Use OpenSSL to verify signature
            $command = sprintf(
                'openssl pkcs7 -inform DER -in %s -print_certs -text 2>&1',
                escapeshellarg($pdfPath)
            );
            
            exec($command, $output, $returnCode);
            
            $isValid = $returnCode === 0;
            $signerInfo = [];
            
            if ($isValid) {
                // Parse output for signer information
                $outputText = implode("\n", $output);
                
                if (preg_match('/Subject:.*CN=([^,\n]+)/', $outputText, $matches)) {
                    $signerInfo['signer'] = $matches[1];
                }
                
                if (preg_match('/Not Before: (.+)/', $outputText, $matches)) {
                    $signerInfo['valid_from'] = $matches[1];
                }
                
                if (preg_match('/Not After : (.+)/', $outputText, $matches)) {
                    $signerInfo['valid_until'] = $matches[1];
                }
            }
            
            return [
                'is_signed' => $isValid,
                'is_valid' => $isValid,
                'signer_info' => $signerInfo,
                'verification_time' => now()->toIso8601String(),
            ];
            
        } catch (Exception $e) {
            return [
                'is_signed' => false,
                'is_valid' => false,
                'error' => $e->getMessage(),
                'verification_time' => now()->toIso8601String(),
            ];
        }
    }
    
    /**
     * Add timestamp to signed PDF
     */
    public function addTimestamp(Document $document, string $tsaUrl): Document
    {
        try {
            $sourcePath = Storage::path($document->stored_name);
            
            // Generate output filename
            $filename = Str::slug(pathinfo($document->original_name, PATHINFO_FILENAME)) 
                . '_timestamped_' . time() . '.pdf';
            $outputPath = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullOutputPath = Storage::path($outputPath);
            
            // Use OpenSSL to add timestamp
            $command = sprintf(
                'openssl ts -query -data %s -sha256 -cert -out - | ' .
                'curl -s -H "Content-Type: application/timestamp-query" --data-binary @- %s | ' .
                'openssl ts -reply -in - -text',
                escapeshellarg($sourcePath),
                escapeshellarg($tsaUrl)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception('Failed to add timestamp: ' . implode("\n", $output));
            }
            
            // Copy file with timestamp metadata
            copy($sourcePath, $fullOutputPath);
            
            // Create new document record
            $timestampedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => str_replace('.pdf', '_timestamped.pdf', $document->original_name),
                'stored_name' => $outputPath,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullOutputPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullOutputPath),
                'metadata' => array_merge($document->metadata ?? [], [
                    'type' => 'timestamped',
                    'source_document' => $document->id,
                    'timestamp_added' => now()->toIso8601String(),
                    'tsa_url' => $tsaUrl,
                ]),
            ]);
            
            return $timestampedDocument;
            
        } catch (Exception $e) {
            throw new Exception('Error adding timestamp: ' . $e->getMessage());
        }
    }
    
    /**
     * Create self-signed certificate for testing
     */
    public function createSelfSignedCertificate(array $details): array
    {
        $dn = [
            "countryName" => $details['country'] ?? 'US',
            "stateOrProvinceName" => $details['state'] ?? 'State',
            "localityName" => $details['city'] ?? 'City',
            "organizationName" => $details['organization'] ?? 'Giga-PDF',
            "organizationalUnitName" => $details['unit'] ?? 'IT',
            "commonName" => $details['common_name'] ?? 'giga-pdf.local',
            "emailAddress" => $details['email'] ?? 'admin@giga-pdf.local'
        ];
        
        // Generate private key
        $privateKey = openssl_pkey_new([
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);
        
        // Generate certificate signing request
        $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
        
        // Generate self-signed certificate (valid for 365 days)
        $x509 = openssl_csr_sign($csr, null, $privateKey, 365, ['digest_alg' => 'sha256']);
        
        // Export certificate and private key
        openssl_x509_export($x509, $certOut);
        openssl_pkey_export($privateKey, $privateKeyOut, $details['password'] ?? null);
        
        // Save to files
        $certPath = storage_path('app/certificates/' . Str::uuid() . '.crt');
        $keyPath = storage_path('app/certificates/' . Str::uuid() . '.key');
        
        // Ensure directory exists
        $dir = dirname($certPath);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($certPath, $certOut);
        file_put_contents($keyPath, $privateKeyOut);
        
        return [
            'certificate_path' => $certPath,
            'private_key_path' => $keyPath,
            'certificate_content' => $certOut,
            'private_key_content' => $privateKeyOut,
            'valid_from' => now()->toIso8601String(),
            'valid_until' => now()->addYear()->toIso8601String(),
        ];
    }
}