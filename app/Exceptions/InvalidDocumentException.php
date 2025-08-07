<?php

namespace App\Exceptions;

use Exception;

class InvalidDocumentException extends Exception
{
    protected $documentId;
    protected $validationErrors;
    
    /**
     * Create a new exception instance.
     */
    public function __construct(string $message = "Invalid document", int $code = 0, ?Exception $previous = null)
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
     * Set validation errors
     */
    public function setValidationErrors(array $errors): self
    {
        $this->validationErrors = $errors;
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
     * Get validation errors
     */
    public function getValidationErrors(): ?array
    {
        return $this->validationErrors;
    }
    
    /**
     * Report the exception.
     */
    public function report(): void
    {
        \Log::warning('Invalid document detected', [
            'document_id' => $this->documentId,
            'validation_errors' => $this->validationErrors,
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
                'error' => 'invalid_document',
                'message' => $this->getMessage(),
                'document_id' => $this->documentId,
                'validation_errors' => $this->validationErrors,
            ], 422);
        }
        
        return redirect()->back()
            ->with('error', 'Invalid document: ' . $this->getMessage())
            ->withErrors($this->validationErrors ?? [])
            ->withInput();
    }
}