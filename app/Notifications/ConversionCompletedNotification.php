<?php

namespace App\Notifications;

use App\Models\Conversion;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConversionCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $conversion;
    protected $resultDocument;

    /**
     * Create a new notification instance.
     */
    public function __construct(Conversion $conversion, Document $resultDocument)
    {
        $this->conversion = $conversion;
        $this->resultDocument = $resultDocument;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $originalDocument = $this->conversion->document;
        
        return (new MailMessage)
            ->subject('Document Conversion Completed')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your document conversion has been completed successfully.')
            ->line('**Original Document:** ' . $originalDocument->original_name)
            ->line('**Converted Format:** ' . $this->conversion->to_format)
            ->line('**Result Document:** ' . $this->resultDocument->original_name)
            ->line('**File Size:** ' . number_format($this->resultDocument->size / 1024 / 1024, 2) . ' MB')
            ->action('Download Document', url('/documents/' . $this->resultDocument->id . '/download'))
            ->line('Thank you for using Giga-PDF!')
            ->salutation('Best regards, The Giga-PDF Team');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'conversion_completed',
            'conversion_id' => $this->conversion->id,
            'document_id' => $this->conversion->document_id,
            'result_document_id' => $this->resultDocument->id,
            'original_name' => $this->conversion->document->original_name,
            'result_name' => $this->resultDocument->original_name,
            'from_format' => $this->conversion->from_format,
            'to_format' => $this->conversion->to_format,
            'completed_at' => $this->conversion->completed_at,
        ];
    }
}