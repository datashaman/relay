<?php

namespace App\Jobs;

use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Enums\StuckState;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Services\ImplementAgent;
use App\Services\OrchestratorService;
use App\Services\PreflightAgent;
use App\Services\ReleaseAgent;
use App\Services\VerifyAgent;
use App\Support\Logging\PipelineLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExecuteStageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public Stage $stage,
        public array $context = [],
    ) {}

    public function handle(): void
    {
        $stage = $this->stage->fresh() ?? $this->stage;

        StageEvent::create([
            'stage_id' => $stage->id,
            'type' => 'job_picked',
            'actor' => 'system',
            'payload' => [
                'stage' => $stage->name->value,
                'iteration' => $stage->iteration,
            ],
        ]);

        PipelineLogger::event($stage->run, 'stage.job_picked', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
        ]);

        match ($this->stage->name) {
            StageName::Preflight => app(PreflightAgent::class)->execute($this->stage, $this->context),
            StageName::Implement => app(ImplementAgent::class)->execute($this->stage, $this->context),
            StageName::Verify => app(VerifyAgent::class)->execute($this->stage, $this->context),
            StageName::Release => app(ReleaseAgent::class)->execute($this->stage, $this->context),
            default => null,
        };
    }

    public function timeout(): int
    {
        return (int) config('relay.orchestrator.stage_job_timeout', 600);
    }

    public function failed(Throwable $e): void
    {
        $stage = Stage::find($this->stage->id);

        if (! $stage || ! in_array($stage->status, [StageStatus::Running, StageStatus::AwaitingApproval], true)) {
            return;
        }

        app(OrchestratorService::class)->markStuck($stage, StuckState::JobFailed, [
            'reason' => mb_substr($e->getMessage(), 0, 500),
            'exception' => $e::class,
        ]);
    }
}
