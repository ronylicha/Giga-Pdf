<?php

namespace App\Services;

use App\Models\Document;
use Exception;
use Illuminate\Support\Facades\Storage;

class PDFEditorService
{
    protected $pdfService;

    public function __construct(PDFService $pdfService)
    {
        $this->pdfService = $pdfService;
    }

    /**
     * Save PDF annotations
     */
    public function saveAnnotations(Document $document, array $annotations): Document
    {
        // Store annotations in metadata
        $metadata = $document->metadata ?? [];
        $metadata['annotations'] = $annotations;
        $metadata['last_edited'] = now()->toIso8601String();

        $document->update(['metadata' => $metadata]);

        return $document;
    }

    /**
     * Add text to PDF
     */
    public function addText(Document $document, array $textElements): Document
    {
        try {
            $pdf = new \Imagick();
            $pdf->readImage(Storage::path($document->stored_name));

            foreach ($textElements as $element) {
                $pageIndex = $element['page'] - 1;
                $pdf->setIteratorIndex($pageIndex);

                $draw = new \ImagickDraw();
                $draw->setFillColor(new \ImagickPixel($element['color'] ?? '#000000'));
                $draw->setFontSize($element['fontSize'] ?? 12);
                $draw->setFont($element['font'] ?? 'Arial');

                $pdf->annotateImage(
                    $draw,
                    $element['x'],
                    $element['y'],
                    0,
                    $element['text']
                );
            }

            // Save edited PDF
            $filename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_edited_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);

            $dir = dirname($fullPath);
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();

            // Create new document record
            $editedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_edited.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'edited',
                    'source_document' => $document->id,
                    'text_elements' => $textElements,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            return $editedDocument;

        } catch (\ImagickException $e) {
            throw new Exception('Erreur lors de l\'ajout de texte: ' . $e->getMessage());
        }
    }

    /**
     * Add images to PDF
     */
    public function addImages(Document $document, array $images): Document
    {
        try {
            $pdf = new \Imagick();
            $pdf->readImage(Storage::path($document->stored_name));

            foreach ($images as $imageData) {
                $pageIndex = $imageData['page'] - 1;
                $pdf->setIteratorIndex($pageIndex);

                // Load image
                $image = new \Imagick();
                if (isset($imageData['base64'])) {
                    $image->readImageBlob(base64_decode($imageData['base64']));
                } elseif (isset($imageData['path'])) {
                    $image->readImage($imageData['path']);
                }

                // Resize if needed
                if (isset($imageData['width']) || isset($imageData['height'])) {
                    $image->resizeImage(
                        $imageData['width'] ?? 0,
                        $imageData['height'] ?? 0,
                        \Imagick::FILTER_LANCZOS,
                        1
                    );
                }

                // Composite image onto PDF page
                $pdf->compositeImage(
                    $image,
                    \Imagick::COMPOSITE_OVER,
                    $imageData['x'],
                    $imageData['y']
                );

                $image->clear();
                $image->destroy();
            }

            // Save edited PDF
            $filename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_with_images_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);

            $dir = dirname($fullPath);
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();

            // Create new document record
            $editedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_with_images.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'edited',
                    'source_document' => $document->id,
                    'images_added' => count($images),
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            return $editedDocument;

        } catch (\ImagickException $e) {
            throw new Exception('Erreur lors de l\'ajout d\'images: ' . $e->getMessage());
        }
    }

    /**
     * Add shapes to PDF
     */
    public function addShapes(Document $document, array $shapes): Document
    {
        try {
            $pdf = new \Imagick();
            $pdf->readImage(Storage::path($document->stored_name));

            foreach ($shapes as $shape) {
                $pageIndex = $shape['page'] - 1;
                $pdf->setIteratorIndex($pageIndex);

                $draw = new \ImagickDraw();
                $draw->setStrokeColor(new \ImagickPixel($shape['strokeColor'] ?? '#000000'));
                $draw->setStrokeWidth($shape['strokeWidth'] ?? 1);

                if (isset($shape['fillColor'])) {
                    $draw->setFillColor(new \ImagickPixel($shape['fillColor']));
                } else {
                    $draw->setFillOpacity(0);
                }

                switch ($shape['type']) {
                    case 'rectangle':
                        $draw->rectangle(
                            $shape['x'],
                            $shape['y'],
                            $shape['x'] + $shape['width'],
                            $shape['y'] + $shape['height']
                        );

                        break;

                    case 'circle':
                        $draw->circle(
                            $shape['x'],
                            $shape['y'],
                            $shape['x'] + $shape['radius'],
                            $shape['y']
                        );

                        break;

                    case 'line':
                        $draw->line(
                            $shape['x1'],
                            $shape['y1'],
                            $shape['x2'],
                            $shape['y2']
                        );

                        break;

                    case 'ellipse':
                        $draw->ellipse(
                            $shape['x'],
                            $shape['y'],
                            $shape['rx'],
                            $shape['ry'],
                            0,
                            360
                        );

                        break;
                }

                $pdf->drawImage($draw);
            }

            // Save edited PDF
            $filename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_with_shapes_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);

            $dir = dirname($fullPath);
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();

            // Create new document record
            $editedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_with_shapes.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'edited',
                    'source_document' => $document->id,
                    'shapes_added' => count($shapes),
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            return $editedDocument;

        } catch (\ImagickException $e) {
            throw new Exception('Erreur lors de l\'ajout de formes: ' . $e->getMessage());
        }
    }

    /**
     * Highlight text in PDF
     */
    public function highlightText(Document $document, array $highlights): Document
    {
        try {
            $pdf = new \Imagick();
            $pdf->readImage(Storage::path($document->stored_name));

            foreach ($highlights as $highlight) {
                $pageIndex = $highlight['page'] - 1;
                $pdf->setIteratorIndex($pageIndex);

                $draw = new \ImagickDraw();
                $draw->setFillColor(new \ImagickPixel($highlight['color'] ?? 'yellow'));
                $draw->setFillOpacity($highlight['opacity'] ?? 0.3);

                // Draw highlight rectangle
                $draw->rectangle(
                    $highlight['x'],
                    $highlight['y'],
                    $highlight['x'] + $highlight['width'],
                    $highlight['y'] + $highlight['height']
                );

                $pdf->drawImage($draw);
            }

            // Save highlighted PDF
            $filename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_highlighted_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);

            $dir = dirname($fullPath);
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();

            // Create new document record
            $editedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_highlighted.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'highlighted',
                    'source_document' => $document->id,
                    'highlights' => $highlights,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            return $editedDocument;

        } catch (\ImagickException $e) {
            throw new Exception('Erreur lors de l\'ajout de surlignage: ' . $e->getMessage());
        }
    }

    /**
     * Add sticky notes/comments to PDF
     */
    public function addComments(Document $document, array $comments): Document
    {
        // Store comments in metadata for now
        // In a real implementation, you might use a PDF library that supports annotations
        $metadata = $document->metadata ?? [];
        $metadata['comments'] = $comments;
        $metadata['last_commented'] = now()->toIso8601String();

        $document->update(['metadata' => $metadata]);

        return $document;
    }

    /**
     * Redact sensitive information from PDF
     */
    public function redactContent(Document $document, array $redactions): Document
    {
        try {
            $pdf = new \Imagick();
            $pdf->readImage(Storage::path($document->stored_name));

            foreach ($redactions as $redaction) {
                $pageIndex = $redaction['page'] - 1;
                $pdf->setIteratorIndex($pageIndex);

                $draw = new \ImagickDraw();
                $draw->setFillColor(new \ImagickPixel($redaction['color'] ?? '#000000'));
                $draw->setFillOpacity(1);

                // Draw redaction rectangle
                $draw->rectangle(
                    $redaction['x'],
                    $redaction['y'],
                    $redaction['x'] + $redaction['width'],
                    $redaction['y'] + $redaction['height']
                );

                $pdf->drawImage($draw);
            }

            // Save redacted PDF
            $filename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_redacted_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);

            $dir = dirname($fullPath);
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();

            // Create new document record
            $redactedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_redacted.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'redacted',
                    'source_document' => $document->id,
                    'redactions_count' => count($redactions),
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            return $redactedDocument;

        } catch (\ImagickException $e) {
            throw new Exception('Erreur lors de la rédaction: ' . $e->getMessage());
        }
    }

    /**
     * Add signature to PDF
     */
    public function addSignature(Document $document, array $signatureData): Document
    {
        try {
            $pdf = new \Imagick();
            $pdf->readImage(Storage::path($document->stored_name));

            $pageIndex = $signatureData['page'] - 1;
            $pdf->setIteratorIndex($pageIndex);

            // Load signature image
            $signature = new \Imagick();

            if (isset($signatureData['base64'])) {
                // Signature provided as base64
                $signature->readImageBlob(base64_decode($signatureData['base64']));
            } elseif (isset($signatureData['path'])) {
                // Signature provided as file path
                $signature->readImage($signatureData['path']);
            }

            // Resize signature if needed
            if (isset($signatureData['width']) || isset($signatureData['height'])) {
                $signature->resizeImage(
                    $signatureData['width'] ?? 0,
                    $signatureData['height'] ?? 0,
                    \Imagick::FILTER_LANCZOS,
                    1,
                    true
                );
            }

            // Place signature on PDF
            $pdf->compositeImage(
                $signature,
                \Imagick::COMPOSITE_OVER,
                $signatureData['x'],
                $signatureData['y']
            );

            $signature->clear();
            $signature->destroy();

            // Save signed PDF
            $filename = pathinfo($document->original_name, PATHINFO_FILENAME) . '_signed_' . time() . '.pdf';
            $path = 'documents/' . $document->tenant_id . '/' . $filename;
            $fullPath = Storage::path($path);

            $dir = dirname($fullPath);
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            $pdf->setImageFormat('pdf');
            $pdf->writeImages($fullPath, true);
            $pdf->clear();
            $pdf->destroy();

            // Create new document record
            $signedDocument = Document::create([
                'tenant_id' => $document->tenant_id,
                'user_id' => $document->user_id,
                'parent_id' => $document->id,
                'original_name' => pathinfo($document->original_name, PATHINFO_FILENAME) . '_signed.pdf',
                'stored_name' => $path,
                'mime_type' => 'application/pdf',
                'size' => filesize($fullPath),
                'extension' => 'pdf',
                'hash' => hash_file('sha256', $fullPath),
                'metadata' => [
                    'type' => 'signed',
                    'source_document' => $document->id,
                    'signed_by' => auth()->id(),
                    'signed_at' => now()->toIso8601String(),
                    'signature_position' => [
                        'page' => $signatureData['page'],
                        'x' => $signatureData['x'],
                        'y' => $signatureData['y'],
                    ],
                ],
            ]);

            return $signedDocument;

        } catch (\ImagickException $e) {
            throw new Exception('Erreur lors de l\'ajout de la signature: ' . $e->getMessage());
        }
    }

    /**
     * Create fillable form fields
     */
    public function createFormFields(Document $document, array $fields): Document
    {
        // This would require a more advanced PDF library like TCPDF or mPDF
        // For now, we'll store the form structure in metadata
        $metadata = $document->metadata ?? [];
        $metadata['form_fields'] = $fields;
        $metadata['is_form'] = true;
        $metadata['form_created'] = now()->toIso8601String();

        $document->update(['metadata' => $metadata]);

        return $document;
    }

    /**
     * Fill form fields with data
     */
    public function fillFormFields(Document $document, array $formData): Document
    {
        // This would require a PDF library that supports form filling
        // For now, we'll store the filled data in metadata
        $metadata = $document->metadata ?? [];
        $metadata['form_data'] = $formData;
        $metadata['form_filled'] = now()->toIso8601String();
        $metadata['filled_by'] = auth()->id();

        $document->update(['metadata' => $metadata]);

        return $document;
    }
}
