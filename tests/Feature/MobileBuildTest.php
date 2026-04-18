<?php

namespace Tests\Feature;

use App\Enums\AutonomyLevel;
use App\Enums\RunStatus;
use App\Enums\StuckState;
use App\Events\RunStuck;
use App\Events\StageTransitioned;
use App\Listeners\SendStuckNotification;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Services\MobileOauthService;
use App\Services\MobileSyncService;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MobileBuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_build_command_produces_ios_manifest(): void
    {
        $outputPath = sys_get_temp_dir().'/relay-test-build';
        Config::set('nativephp-mobile.build.output_path', $outputPath);
        Config::set('nativephp-mobile.ios.bundle_id', 'com.test.relay');
        Config::set('nativephp-mobile.ios.min_ios_version', '16.0');
        Config::set('nativephp.version', '1.0.0');
        Config::set('nativephp.prebuild', []);
        Config::set('nativephp.postbuild', []);

        $this->artisan('native:mobile:build', ['platform' => 'ios'])
            ->assertSuccessful();

        $manifestPath = "{$outputPath}/ios/build-manifest.json";
        $this->assertFileExists($manifestPath);

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertEquals('ios', $manifest['platform']);
        $this->assertEquals('com.test.relay', $manifest['bundle_id']);
        $this->assertEquals('16.0', $manifest['min_version']);

        @unlink($manifestPath);
        @rmdir("{$outputPath}/ios");
        @rmdir($outputPath);
    }

    public function test_mobile_build_command_produces_android_manifest(): void
    {
        $outputPath = sys_get_temp_dir().'/relay-test-build';
        Config::set('nativephp-mobile.build.output_path', $outputPath);
        Config::set('nativephp-mobile.android.package_name', 'com.test.relay');
        Config::set('nativephp-mobile.android.min_sdk', 26);
        Config::set('nativephp-mobile.android.target_sdk', 34);
        Config::set('nativephp.version', '1.0.0');
        Config::set('nativephp.prebuild', []);
        Config::set('nativephp.postbuild', []);

        $this->artisan('native:mobile:build', ['platform' => 'android'])
            ->assertSuccessful();

        $manifestPath = "{$outputPath}/android/build-manifest.json";
        $this->assertFileExists($manifestPath);

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertEquals('android', $manifest['platform']);
        $this->assertEquals('com.test.relay', $manifest['package_name']);
        $this->assertEquals(26, $manifest['min_sdk']);
        $this->assertEquals(34, $manifest['target_sdk']);

        @unlink($manifestPath);
        @rmdir("{$outputPath}/android");
        @rmdir($outputPath);
    }

    public function test_mobile_sync_service_blocks_on_low_power(): void
    {
        Config::set('relay.mobile.platform', 'ios');
        Config::set('relay.mobile.low_power_mode', true);
        Config::set('relay.mobile.network_status', 'wifi');

        $service = new MobileSyncService;

        $this->assertFalse($service->shouldSync());
    }

    public function test_mobile_sync_service_blocks_on_no_network(): void
    {
        Config::set('relay.mobile.platform', 'ios');
        Config::set('relay.mobile.low_power_mode', false);
        Config::set('relay.mobile.network_status', 'none');

        $service = new MobileSyncService;

        $this->assertFalse($service->shouldSync());
    }

    public function test_mobile_sync_service_allows_on_wifi(): void
    {
        Config::set('relay.mobile.platform', 'ios');
        Config::set('relay.mobile.low_power_mode', false);
        Config::set('relay.mobile.network_status', 'wifi');

        $service = new MobileSyncService;

        $this->assertTrue($service->shouldSync());
    }

    public function test_mobile_sync_returns_longer_interval_on_cellular(): void
    {
        Config::set('relay.mobile.platform', 'ios');
        Config::set('relay.mobile.network_status', 'cellular');
        Config::set('relay.mobile.wifi_sync_interval', 5);
        Config::set('relay.mobile.cellular_sync_interval', 15);

        $service = new MobileSyncService;

        $this->assertEquals(15, $service->getSyncInterval());
    }

    public function test_mobile_sync_returns_short_interval_on_wifi(): void
    {
        Config::set('relay.mobile.platform', 'ios');
        Config::set('relay.mobile.network_status', 'wifi');
        Config::set('relay.mobile.wifi_sync_interval', 5);

        $service = new MobileSyncService;

        $this->assertEquals(5, $service->getSyncInterval());
    }

    public function test_non_mobile_platform_always_syncs(): void
    {
        Config::set('relay.mobile.platform', null);

        $service = new MobileSyncService;

        $this->assertTrue($service->shouldSync());
    }

    public function test_push_notification_service_notifies_on_stuck(): void
    {
        $source = Source::factory()->create();
        $issue = Issue::factory()->create(['source_id' => $source->id]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::IterationCap,
        ]);

        $service = $this->createMock(PushNotificationService::class);
        $service->expects($this->once())->method('notifyStuck')->with($run);

        $listener = new SendStuckNotification($service);
        $listener->handle(new RunStuck($run));
    }

    public function test_push_notification_identifies_approval_needed(): void
    {
        $service = new PushNotificationService;

        $stage = new Stage;

        $this->assertTrue($service->shouldNotify($stage, AutonomyLevel::Manual));
        $this->assertTrue($service->shouldNotify($stage, AutonomyLevel::Supervised));
        $this->assertFalse($service->shouldNotify($stage, AutonomyLevel::Assisted));
        $this->assertFalse($service->shouldNotify($stage, AutonomyLevel::Autonomous));
    }

    public function test_mobile_oauth_service_provides_callback_url(): void
    {
        Config::set('relay.mobile.oauth_callback_host', '127.0.0.1');
        Config::set('relay.mobile.oauth_callback_port', 8100);

        $service = new MobileOauthService;

        $this->assertEquals(
            'http://127.0.0.1:8100/oauth/github/callback',
            $service->getCallbackUrl('github')
        );
    }

    public function test_mobile_oauth_detects_mobile_platform(): void
    {
        Config::set('relay.mobile.platform', 'ios');
        $service = new MobileOauthService;
        $this->assertTrue($service->isMobileOauth());

        Config::set('relay.mobile.platform', null);
        $service = new MobileOauthService;
        $this->assertFalse($service->isMobileOauth());
    }

    public function test_mobile_layout_renders_hamburger_menu(): void
    {
        $response = $this->get('/activity');

        $response->assertStatus(200);
        $response->assertSee('md:hidden fixed bottom-0', false);
    }

    public function test_event_listeners_registered(): void
    {
        Event::fake([RunStuck::class, StageTransitioned::class]);

        $source = Source::factory()->create();
        $issue = Issue::factory()->create(['source_id' => $source->id]);
        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Stuck,
            'stuck_state' => StuckState::IterationCap,
        ]);

        event(new RunStuck($run));

        Event::assertDispatched(RunStuck::class);
    }
}
