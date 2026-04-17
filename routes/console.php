<?php

use App\Services\MergeConflictDetector;
use App\Services\MobileSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    app(MobileSyncService::class)->syncIfAppropriate();
})->everyMinute()->name('sync-source-issues');

Schedule::call(function () {
    app(MergeConflictDetector::class)->probeAllActive();
})->everyFiveMinutes()->name('detect-merge-conflicts')->withoutOverlapping();
