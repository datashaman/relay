<?php

namespace App\Providers;

use App\Events\RunStuck;
use App\Events\StageTransitioned;
use App\Listeners\SendApprovalNotification;
use App\Listeners\SendStuckNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(RunStuck::class, SendStuckNotification::class);
        Event::listen(StageTransitioned::class, SendApprovalNotification::class);
    }
}
