<?php

namespace App\Events;

use App\Models\Conversion;
use App\Models\Document;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversionCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $conversion;
    public $resultDocument;

    /**
     * Create a new event instance.
     */
    public function __construct(Conversion $conversion, Document $resultDocument)
    {
        $this->conversion = $conversion;
        $this->resultDocument = $resultDocument;
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
            'result_document_id' => $this->resultDocument->id,
            'result_document_name' => $this->resultDocument->original_name,
            'result_document_size' => $this->resultDocument->size,
            'status' => 'completed',
            'completed_at' => $this->conversion->completed_at,
        ];
    }
}