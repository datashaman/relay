<?php

namespace App\Events;

use App\Models\Stage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StageTransitioned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Stage $stage,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('run.'.$this->stage->run_id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'stage_id' => $this->stage->id,
            'run_id' => $this->stage->run_id,
            'name' => $this->stage->name->value,
            'status' => $this->stage->status->value,
            'iteration' => $this->stage->iteration,
        ];
    }
}
