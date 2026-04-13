<?php

namespace Tests\Feature;

use App\Services\MobileOauthService;
use Tests\TestCase;

class MobileOauthServiceTest extends TestCase
{
    private MobileOauthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MobileOauthService;
    }

    public function test_get_callback_url_returns_configured_url(): void
    {
        config([
            'relay.mobile.oauth_callback_host' => '127.0.0.1',
            'relay.mobile.oauth_callback_port' => 8100,
        ]);

        $url = $this->service->getCallbackUrl('github');

        $this->assertEquals('http://127.0.0.1:8100/oauth/github/callback', $url);
    }

    public function test_get_callback_url_uses_custom_host_and_port(): void
    {
        config([
            'relay.mobile.oauth_callback_host' => '192.168.1.1',
            'relay.mobile.oauth_callback_port' => 9090,
        ]);

        $url = $this->service->getCallbackUrl('jira');

        $this->assertEquals('http://192.168.1.1:9090/oauth/jira/callback', $url);
    }

    public function test_is_mobile_oauth_returns_true_when_platform_set(): void
    {
        config(['relay.mobile.platform' => 'ios']);

        $this->assertTrue($this->service->isMobileOauth());
    }

    public function test_is_mobile_oauth_returns_false_when_no_platform(): void
    {
        config(['relay.mobile.platform' => null]);

        $this->assertFalse($this->service->isMobileOauth());
    }
}
