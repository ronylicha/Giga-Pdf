<?php

namespace App\Jobs;

use App\Models\Conversion;
use App\Models\Document;
use App\Services\ConversionService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDocumentConversion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $document;
    protected $targetFormat;
    protected $options;
    protected $conversionId;

    public $tries = 3;
    public $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(Document $document, string $targetFormat, array $options = [], ?int $conversionId = null)
    {
        $this->document = $document;
        $this->targetFormat = $targetFormat;
        $this->options = $options;
        $this->conversionId = $conversionId;

        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(ConversionService $conversionService)
    {
        $conversion = null;

        try {
            // Get or create conversion record
            if ($this->conversionId) {
                $conversion = Conversion::find($this->conversionId);
            }

            if (! $conversion) {
                $conversion = Conversion::create([
                    'tenant_id' => $this->document->tenant_id,
                    'document_id' => $this->document->id,
                    'user_id' => $this->document->user_id,
                    'from_format' => $this->getFormatFromMimeType($this->document->mime_type),
                    'to_format' => $this->targetFormat,
                    'status' => 'pending',
                    'options' => $this->options,
                ]);
            }

            // Mark as processing
            $conversion->markAsProcessing();
            $conversion->update(['queue_id' => $this->job->getJobId()]);

            // Notify user that conversion started
            $this->notifyProgress($conversion, 10, 'Starting conversion...');

            // Perform conversion
            if ($this->targetFormat === 'pdf') {
                $this->notifyProgress($conversion, 30, 'Converting to PDF...');
                $resultDocument = $conversionService->convertToPDF($this->document, $this->options);
            } else {
                $this->notifyProgress($conversion, 30, 'Converting from PDF...');
                $resultDocument = $conversionService->convertFromPDF($this->document, $this->targetFormat, $this->options);
            }

            $this->notifyProgress($conversion, 90, 'Finalizing...');

            // Mark as completed
            $conversion->markAsCompleted($resultDocument->id);

            // Send completion notification
            $this->notifyCompletion($conversion, $resultDocument);

            Log::info('Document conversion completed successfully', [
                'conversion_id' => $conversion->id,
                'document_id' => $this->document->id,
                'target_format' => $this->targetFormat,
                'result_document_id' => $resultDocument->id,
            ]);

        } catch (Exception $e) {
            Log::error('Document conversion failed', [
                'conversion_id' => $conversion?->id,
                'document_id' => $this->document->id,
                'target_format' => $this->targetFormat,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($conversion) {
                $conversion->markAsFailed($e->getMessage());
                $this->notifyFailure($conversion, $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Notify progress via websocket
     */
    protected function notifyProgress(Conversion $conversion, int $progress, string $message = ''): void
    {
        $conversion->updateProgress($progress);

        // Broadcast progress update
        broadcast(new \App\Events\ConversionProgress(
            $conversion,
            $progress,
            $message
        ))->toOthers();
    }

    /**
     * Notify completion
     */
    protected function notifyCompletion(Conversion $conversion, Document $resultDocument): void
    {
        // Broadcast completion
        broadcast(new \App\Events\ConversionCompleted(
            $conversion,
            $resultDocument
        ))->toOthers();

        // Send email notification if enabled
        if ($conversion->user && $conversion->user->getPreference('email_on_conversion_complete', true)) {
            $conversion->user->notify(new \App\Notifications\ConversionCompletedNotification(
                $conversion,
                $resultDocument
            ));
        }
    }

    /**
     * Notify failure
     */
    protected function notifyFailure(Conversion $conversion, string $error): void
    {
        // Broadcast failure
        broadcast(new \App\Events\ConversionFailed(
            $conversion,
            $error
        ))->toOthers();

        // Send email notification if enabled
        if ($conversion->user && $conversion->user->getPreference('email_on_conversion_fail', true)) {
            $conversion->user->notify(new \App\Notifications\ConversionFailedNotification(
                $conversion,
                $error
            ));
        }
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
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'text/html' => 'html',
            'text/plain' => 'txt',
            'text/markdown' => 'md',
        ];

        return $map[$mimeType] ?? 'unknown';
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception)
    {
        Log::error('Document conversion job failed permanently', [
            'document_id' => $this->document->id,
            'target_format' => $this->targetFormat,
            'error' => $exception->getMessage(),
        ]);

        if ($this->conversionId) {
            $conversion = Conversion::find($this->conversionId);
            if ($conversion && ! $conversion->isFailed()) {
                $conversion->markAsFailed('Job failed: ' . $exception->getMessage());
                $this->notifyFailure($conversion, $exception->getMessage());
            }
        }
    }

    /**
     * Determine if job should retry
     */
    public function shouldRetry(Exception $exception): bool
    {
        // Don't retry for certain exceptions
        if ($exception instanceof \App\Exceptions\InvalidDocumentException) {
            return false;
        }

        if ($exception instanceof \App\Exceptions\StorageQuotaExceededException) {
            return false;
        }

        return true;
    }
}
