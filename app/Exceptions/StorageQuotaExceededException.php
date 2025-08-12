<?php

namespace App\Exceptions;

use Exception;

class StorageQuotaExceededException extends Exception
{
    /**
     * Create a new exception instance.
     */
    public function __construct(string $message = "Storage quota exceeded", int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Report the exception.
     */
    public function report(): void
    {
        // Log cette exception comme importante
        \Log::warning('Storage quota exceeded', [
            'user_id' => auth()->id(),
            'tenant_id' => auth()->user()?->tenant_id,
            'message' => $this->getMessage(),
        ]);
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'storage_quota_exceeded',
                'message' => $this->getMessage(),
            ], 413); // 413 Payload Too Large
        }

        return redirect()->back()
            ->with('error', $this->getMessage())
            ->withInput();
    }
}
