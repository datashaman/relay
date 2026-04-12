<?php

namespace App\Listeners;

use App\Events\RunStuck;
use App\Services\PushNotificationService;

class SendStuckNotification
{
    public function __construct(
        protected PushNotificationService $notifications,
    ) {}

    public function handle(RunStuck $event): void
    {
        $this->notifications->notifyStuck($event->run);
    }
}
