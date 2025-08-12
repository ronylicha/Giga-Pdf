<?php

namespace App\Events;

use App\Models\Conversion;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversionFailed implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public $conversion;
    public $error;

    /**
     * Create a new event instance.
     */
    public function __construct(Conversion $conversion, string $error)
    {
        $this->conversion = $conversion;
        $this->error = $error;
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
            'error' => $this->error,
            'status' => 'failed',
            'failed_at' => now()->toIso8601String(),
        ];
    }
}
