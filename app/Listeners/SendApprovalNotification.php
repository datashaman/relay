<?php

namespace App\Listeners;

use App\Events\StageTransitioned;
use App\Services\EscalationRuleService;
use App\Services\PushNotificationService;

class SendApprovalNotification
{
    public function __construct(
        protected PushNotificationService $notifications,
        protected EscalationRuleService $escalation,
    ) {}

    public function handle(StageTransitioned $event): void
    {
        $stage = $event->stage;
        $run = $stage->run;
        $issue = $run->issue;

        $effectiveLevel = $this->escalation->resolveWithEscalation($issue, $stage->name);

        if ($this->notifications->shouldNotify($stage, $effectiveLevel)) {
            $this->notifications->notifyApprovalNeeded($stage);
        }
    }
}
