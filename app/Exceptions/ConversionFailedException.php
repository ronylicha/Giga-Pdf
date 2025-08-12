<?php

namespace App\Exceptions;

use Exception;

class ConversionFailedException extends Exception
{
    protected $documentId;
    protected $conversionId;

    /**
     * Create a new exception instance.
     */
    public function __construct(string $message = "Conversion failed", int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Set document ID
     */
    public function setDocumentId(int $documentId): self
    {
        $this->documentId = $documentId;

        return $this;
    }

    /**
     * Set conversion ID
     */
    public function setConversionId(int $conversionId): self
    {
        $this->conversionId = $conversionId;

        return $this;
    }

    /**
     * Get document ID
     */
    public function getDocumentId(): ?int
    {
        return $this->documentId;
    }

    /**
     * Get conversion ID
     */
    public function getConversionId(): ?int
    {
        return $this->conversionId;
    }

    /**
     * Report the exception.
     */
    public function report(): void
    {
        \Log::error('Document conversion failed', [
            'document_id' => $this->documentId,
            'conversion_id' => $this->conversionId,
            'message' => $this->getMessage(),
            'user_id' => auth()->id(),
            'tenant_id' => auth()->user()?->tenant_id,
        ]);
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'conversion_failed',
                'message' => $this->getMessage(),
                'document_id' => $this->documentId,
                'conversion_id' => $this->conversionId,
            ], 422);
        }

        return redirect()->back()
            ->with('error', 'Document conversion failed: ' . $this->getMessage())
            ->withInput();
    }
}
