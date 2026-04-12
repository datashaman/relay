<?php

namespace App\Services;

use App\Models\OauthToken;
use App\Models\Source;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OauthService
{
    public function providerConfig(string $provider): array
    {
        $config = config("services.{$provider}");

        if (! $config || empty($config['client_id']) || empty($config['client_secret'])) {
            throw new \InvalidArgumentException("OAuth provider [{$provider}] is not configured.");
        }

        return $config;
    }

    public function generateAuthUrl(string $provider): string
    {
        $config = $this->providerConfig($provider);
        $state = Str::random(40);

        Cache::put("oauth_state:{$state}", $provider, now()->addMinutes(10));

        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'state' => $state,
            'response_type' => 'code',
        ];

        if (! empty($config['scopes'])) {
            $separator = $provider === 'jira' ? ' ' : ',';
            $params['scope'] = implode($separator, $config['scopes']);
        }

        if ($provider === 'jira') {
            $params['audience'] = 'api.atlassian.com';
            $params['prompt'] = 'consent';
        }

        return $config['authorize_url'] . '?' . http_build_query($params);
    }

    public function validateState(string $state): string
    {
        $provider = Cache::pull("oauth_state:{$state}");

        if (! $provider) {
            throw new \RuntimeException('Invalid or expired OAuth state parameter.');
        }

        return $provider;
    }

    public function exchangeCode(string $provider, string $code): array
    {
        $config = $this->providerConfig($provider);

        $response = Http::post($config['token_url'], [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code' => $code,
            'redirect_uri' => $config['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    public function storeToken(Source $source, string $provider, array $tokenData): OauthToken
    {
        return $source->oauthTokens()->updateOrCreate(
            ['provider' => $provider],
            [
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'expires_at' => isset($tokenData['expires_in'])
                    ? now()->addSeconds($tokenData['expires_in'])
                    : null,
                'scopes' => isset($tokenData['scope'])
                    ? explode($provider === 'jira' ? ' ' : ',', $tokenData['scope'])
                    : null,
            ],
        );
    }

    public function refreshToken(OauthToken $token): OauthToken
    {
        if (! $token->refresh_token) {
            throw new \RuntimeException('No refresh token available.');
        }

        $config = $this->providerConfig($token->provider);

        $response = Http::post($config['token_url'], [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $token->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Token refresh failed: ' . $response->body());
        }

        $data = $response->json();

        $token->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $token->refresh_token,
            'expires_at' => isset($data['expires_in'])
                ? now()->addSeconds($data['expires_in'])
                : $token->expires_at,
        ]);

        return $token->fresh();
    }

    public function refreshIfExpired(OauthToken $token): OauthToken
    {
        if ($token->expires_at && $token->expires_at->isPast()) {
            return $this->refreshToken($token);
        }

        return $token;
    }

    public function authenticatedRequest(OauthToken $token, string $method, string $url, array $options = []): Response
    {
        $token = $this->refreshIfExpired($token);

        $response = Http::withToken($token->access_token)->$method($url, $options);

        if ($response->status() === 401 && $token->refresh_token) {
            $token = $this->refreshToken($token);
            $response = Http::withToken($token->access_token)->$method($url, $options);
        }

        return $response;
    }
}
