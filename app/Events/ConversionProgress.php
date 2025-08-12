<?php

namespace App\Events;

use App\Models\Conversion;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversionProgress implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public $conversion;
    public $progress;
    public $message;

    /**
     * Create a new event instance.
     */
    public function __construct(Conversion $conversion, int $progress, string $message = '')
    {
        $this->conversion = $conversion;
        $this->progress = $progress;
        $this->message = $message;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.' . $this->conversion->tenant_id),
            new PrivateChannel('user.' . $this->conversion->user_id),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'conversion_id' => $this->conversion->id,
            'document_id' => $this->conversion->document_id,
            'progress' => $this->progress,
            'message' => $this->message,
            'status' => $this->conversion->status,
        ];
    }
}
