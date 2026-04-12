<?php

namespace App\Services;

use App\Jobs\SyncSourceIssuesJob;
use App\Models\Source;

class MobileSyncService
{
    public function shouldSync(): bool
    {
        if (! $this->isMobilePlatform()) {
            return true;
        }

        if ($this->isLowPowerMode()) {
            return false;
        }

        if (! $this->hasNetworkConnectivity()) {
            return false;
        }

        return true;
    }

    public function getSyncInterval(): int
    {
        if (! $this->isMobilePlatform()) {
            return (int) config('relay.mobile.sync_interval', 5);
        }

        if ($this->isOnCellular()) {
            return (int) config('relay.mobile.cellular_sync_interval', 15);
        }

        return (int) config('relay.mobile.wifi_sync_interval', 5);
    }

    public function syncIfAppropriate(): void
    {
        if (! $this->shouldSync()) {
            return;
        }

        $interval = $this->getSyncInterval();

        Source::where('is_active', true)->each(function (Source $source) use ($interval) {
            $effectiveInterval = $source->sync_interval ?? $interval;

            if ($source->last_synced_at && $source->last_synced_at->diffInMinutes(now()) < $effectiveInterval) {
                return;
            }

            if ($source->next_retry_at && $source->next_retry_at->isFuture()) {
                return;
            }

            SyncSourceIssuesJob::dispatch($source);
        });
    }

    public function isMobilePlatform(): bool
    {
        return in_array(config('relay.mobile.platform'), ['ios', 'android']);
    }

    public function isLowPowerMode(): bool
    {
        return (bool) config('relay.mobile.low_power_mode', false);
    }

    public function hasNetworkConnectivity(): bool
    {
        return config('relay.mobile.network_status', 'wifi') !== 'none';
    }

    public function isOnCellular(): bool
    {
        return config('relay.mobile.network_status') === 'cellular';
    }
}
