<?php

namespace App\Jobs;

use App\Models\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteStageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Stage $stage,
        public array $context = [],
    ) {}

    public function handle(): void
    {
        // Stage execution delegated to stage-specific agents (US-017..US-022).
    }
}
