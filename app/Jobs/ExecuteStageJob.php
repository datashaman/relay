<?php

namespace App\Jobs;

use App\Enums\StageName;
use App\Models\Stage;
use App\Services\ImplementAgent;
use App\Services\PreflightAgent;
use App\Services\ReleaseAgent;
use App\Services\VerifyAgent;
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
            StageName::Implement => app(ImplementAgent::class)->execute($this->stage, $this->context),
            StageName::Verify => app(VerifyAgent::class)->execute($this->stage, $this->context),
            StageName::Release => app(ReleaseAgent::class)->execute($this->stage, $this->context),
            default => null,
        };
    }
}
