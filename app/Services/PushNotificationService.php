<?php

namespace App\Services;

use App\Enums\AutonomyLevel;
use App\Models\Run;
use App\Models\Stage;
use Native\Laravel\Notification;

class PushNotificationService
{
    public function notifyStuck(Run $run): void
    {
        if (! $this->nativeRuntime()) {
            return;
        }

        $issue = $run->issue;
        $stuckState = $run->stuck_state?->value ?? 'unknown';

        Notification::new()
            ->title('Pipeline Stuck')
            ->message("{$issue->title} — {$stuckState}")
            ->event('run.stuck.'.$run->id)
            ->show();
    }

    public function notifyApprovalNeeded(Stage $stage): void
    {
        if (! $this->nativeRuntime()) {
            return;
        }

        $run = $stage->run;
        $issue = $run->issue;
        $stageName = ucfirst($stage->name->value);

        Notification::new()
            ->title('Approval Required')
            ->message("{$stageName}: {$issue->title}")
            ->event('stage.approval.'.$stage->id)
            ->show();
    }

    private function nativeRuntime(): bool
    {
        return (bool) config('nativephp-internal.running', false);
    }

    public function shouldNotify(Stage $stage, AutonomyLevel $level): bool
    {
        return in_array($level, [AutonomyLevel::Manual, AutonomyLevel::Supervised]);
    }
}
