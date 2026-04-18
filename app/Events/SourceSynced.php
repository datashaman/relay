<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SourceSynced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $sourceId,
        public bool $success,
        public ?string $errorMessage,
        public ?string $lastSyncedAt,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('intake'),
        ];
    }

    /**
     * @return array{source_id: int, success: bool, error_message: ?string, last_synced_at: ?string}
     */
    public function broadcastWith(): array
    {
        return [
            'source_id' => $this->sourceId,
            'success' => $this->success,
            'error_message' => $this->errorMessage,
            'last_synced_at' => $this->lastSyncedAt,
        ];
    }

    public function broadcastAs(): string
    {
        return 'SourceSynced';
    }
}
