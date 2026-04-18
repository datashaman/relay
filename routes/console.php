<?php

use App\Enums\SourceType;
use App\Models\OauthToken;
use App\Models\Source;
use App\Services\JiraWebhookManager;
use App\Services\MergeConflictDetector;
use App\Services\MobileSyncService;
use App\Services\OauthService;
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

Artisan::command('jira:refresh-webhooks', function (OauthService $oauth, JiraWebhookManager $webhooks) {
    $sources = Source::where('type', SourceType::Jira->value)->get();

    foreach ($sources as $source) {
        $ids = $source->config['managed_jira_webhook']['webhook_ids'] ?? [];

        if ($ids === []) {
            continue;
        }

        $token = OauthToken::query()
            ->where('source_id', $source->id)
            ->where('provider', 'jira')
            ->first();

        if (! $token) {
            continue;
        }

        try {
            $webhooks->refreshForSource($source, $oauth->refreshIfExpired($token), $oauth);
            $this->info("Refreshed webhooks for source #{$source->id}.");
        } catch (Throwable $e) {
            $this->error("Failed to refresh source #{$source->id}: ".$e->getMessage());
        }
    }
})->purpose('Refresh Jira dynamic webhooks before their 30-day expiry');

Schedule::command('jira:refresh-webhooks')->daily()->name('refresh-jira-webhooks')->withoutOverlapping();
