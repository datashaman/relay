<?php

namespace App\Events;

use App\Models\Run;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RunStuck implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Run $run,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('run.'.$this->run->id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->run->id,
            'issue_id' => $this->run->issue_id,
            'stuck_state' => $this->run->stuck_state->value,
            'iteration' => $this->run->iteration,
        ];
    }
}
