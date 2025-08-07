<?php

namespace App\Notifications;

use App\Models\Conversion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConversionFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $conversion;
    protected $error;

    /**
     * Create a new notification instance.
     */
    public function __construct(Conversion $conversion, string $error)
    {
        $this->conversion = $conversion;
        $this->error = $error;
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
            ->subject('Document Conversion Failed')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->error()
            ->line('Unfortunately, your document conversion has failed.')
            ->line('**Document:** ' . $originalDocument->original_name)
            ->line('**Attempted Conversion:** ' . $this->conversion->from_format . ' → ' . $this->conversion->to_format)
            ->line('**Error:** ' . $this->error)
            ->line('Please try again or contact support if the issue persists.')
            ->action('View Document', url('/documents/' . $this->conversion->document_id))
            ->line('We apologize for any inconvenience.')
            ->salutation('Best regards, The Giga-PDF Team');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'conversion_failed',
            'conversion_id' => $this->conversion->id,
            'document_id' => $this->conversion->document_id,
            'original_name' => $this->conversion->document->original_name,
            'from_format' => $this->conversion->from_format,
            'to_format' => $this->conversion->to_format,
            'error' => $this->error,
            'failed_at' => now()->toIso8601String(),
        ];
    }
}