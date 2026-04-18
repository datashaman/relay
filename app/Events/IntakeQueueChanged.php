<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IntakeQueueChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $sourceId,
        public string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('intake'),
        ];
    }

    /**
     * @return array{source_id: int, action: string}
     */
    public function broadcastWith(): array
    {
        return [
            'source_id' => $this->sourceId,
            'action' => $this->action,
        ];
    }

    public function broadcastAs(): string
    {
        return 'IntakeQueueChanged';
    }
}
