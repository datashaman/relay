<?php

namespace App\Services;

use Native\Laravel\Shell;

class MobileOauthService
{
    public function openAuthUrl(string $url): void
    {
        Shell::openExternal($url);
    }

    public function getCallbackUrl(string $provider): string
    {
        $host = config('relay.mobile.oauth_callback_host', '127.0.0.1');
        $port = config('relay.mobile.oauth_callback_port', 8100);

        return "http://{$host}:{$port}/oauth/{$provider}/callback";
    }

    public function isMobileOauth(): bool
    {
        return (bool) config('relay.mobile.platform');
    }
}
