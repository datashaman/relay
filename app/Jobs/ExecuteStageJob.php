<?php

namespace App\Jobs;

use App\Enums\StageName;
use App\Models\Stage;
use App\Services\PreflightAgent;
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
        match ($this->stage->name) {
            StageName::Preflight => app(PreflightAgent::class)->execute($this->stage, $this->context),
            default => null, // Other agents: US-019..US-022
        };
    }
}
