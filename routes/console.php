<?php

use App\Jobs\SyncSourceIssuesJob;
use App\Models\Source;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Source::where('is_active', true)->each(function (Source $source) {
        $interval = $source->sync_interval ?? 5;

        if ($source->last_synced_at && $source->last_synced_at->diffInMinutes(now()) < $interval) {
            return;
        }

        if ($source->next_retry_at && $source->next_retry_at->isFuture()) {
            return;
        }

        SyncSourceIssuesJob::dispatch($source);
    });
})->everyMinute()->name('sync-source-issues');
