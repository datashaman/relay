<?php

namespace Tests\Feature;

use App\Jobs\SyncSourceIssuesJob;
use App\Models\Source;
use App\Services\MobileSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MobileSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private MobileSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MobileSyncService;
    }

    public function test_should_sync_returns_true_on_desktop(): void
    {
        config(['relay.mobile.platform' => null]);

        $this->assertTrue($this->service->shouldSync());
    }

    public function test_should_sync_returns_true_on_mobile_with_wifi(): void
    {
        config([
            'relay.mobile.platform' => 'ios',
            'relay.mobile.low_power_mode' => false,
            'relay.mobile.network_status' => 'wifi',
        ]);

        $this->assertTrue($this->service->shouldSync());
    }

    public function test_should_sync_returns_false_in_low_power(): void
    {
        config([
            'relay.mobile.platform' => 'ios',
            'relay.mobile.low_power_mode' => true,
        ]);

        $this->assertFalse($this->service->shouldSync());
    }

    public function test_should_sync_returns_false_with_no_network(): void
    {
        config([
            'relay.mobile.platform' => 'android',
            'relay.mobile.low_power_mode' => false,
            'relay.mobile.network_status' => 'none',
        ]);

        $this->assertFalse($this->service->shouldSync());
    }

    public function test_get_sync_interval_returns_default_on_desktop(): void
    {
        config(['relay.mobile.platform' => null]);

        $this->assertEquals(5, $this->service->getSyncInterval());
    }

    public function test_get_sync_interval_returns_wifi_interval_on_mobile(): void
    {
        config([
            'relay.mobile.platform' => 'ios',
            'relay.mobile.network_status' => 'wifi',
            'relay.mobile.wifi_sync_interval' => 5,
        ]);

        $this->assertEquals(5, $this->service->getSyncInterval());
    }

    public function test_get_sync_interval_returns_cellular_interval(): void
    {
        config([
            'relay.mobile.platform' => 'ios',
            'relay.mobile.network_status' => 'cellular',
            'relay.mobile.cellular_sync_interval' => 15,
        ]);

        $this->assertEquals(15, $this->service->getSyncInterval());
    }

    public function test_sync_if_appropriate_dispatches_jobs(): void
    {
        Queue::fake();
        config(['relay.mobile.platform' => null]);

        $source = Source::factory()->create([
            'is_active' => true,
            'last_synced_at' => null,
        ]);

        $this->service->syncIfAppropriate();

        Queue::assertPushed(SyncSourceIssuesJob::class, function ($job) use ($source) {
            return $job->source->id === $source->id;
        });
    }

    public function test_sync_if_appropriate_skips_recently_synced(): void
    {
        Queue::fake();
        config(['relay.mobile.platform' => null]);

        Source::factory()->create([
            'is_active' => true,
            'last_synced_at' => now()->subMinute(),
        ]);

        $this->service->syncIfAppropriate();

        Queue::assertNotPushed(SyncSourceIssuesJob::class);
    }

    public function test_sync_if_appropriate_skips_when_should_sync_false(): void
    {
        Queue::fake();
        config([
            'relay.mobile.platform' => 'ios',
            'relay.mobile.low_power_mode' => true,
        ]);

        Source::factory()->create([
            'is_active' => true,
            'last_synced_at' => null,
        ]);

        $this->service->syncIfAppropriate();

        Queue::assertNotPushed(SyncSourceIssuesJob::class);
    }

    public function test_sync_if_appropriate_skips_sources_with_future_retry(): void
    {
        Queue::fake();
        config(['relay.mobile.platform' => null]);

        Source::factory()->create([
            'is_active' => true,
            'last_synced_at' => null,
            'next_retry_at' => now()->addMinutes(5),
        ]);

        $this->service->syncIfAppropriate();

        Queue::assertNotPushed(SyncSourceIssuesJob::class);
    }

    public function test_is_mobile_platform_detects_ios(): void
    {
        config(['relay.mobile.platform' => 'ios']);
        $this->assertTrue($this->service->isMobilePlatform());
    }

    public function test_is_mobile_platform_detects_android(): void
    {
        config(['relay.mobile.platform' => 'android']);
        $this->assertTrue($this->service->isMobilePlatform());
    }

    public function test_is_mobile_platform_returns_false_for_desktop(): void
    {
        config(['relay.mobile.platform' => null]);
        $this->assertFalse($this->service->isMobilePlatform());
    }

    public function test_is_on_cellular(): void
    {
        config(['relay.mobile.network_status' => 'cellular']);
        $this->assertTrue($this->service->isOnCellular());

        config(['relay.mobile.network_status' => 'wifi']);
        $this->assertFalse($this->service->isOnCellular());
    }

    public function test_has_network_connectivity(): void
    {
        config(['relay.mobile.network_status' => 'wifi']);
        $this->assertTrue($this->service->hasNetworkConnectivity());

        config(['relay.mobile.network_status' => 'none']);
        $this->assertFalse($this->service->hasNetworkConnectivity());
    }
}
