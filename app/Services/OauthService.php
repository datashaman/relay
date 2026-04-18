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
    private function httpTimeout(): int
    {
        return (int) config('relay.http.oauth_timeout', 30);
    }

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

        return $config['authorize_url'].'?'.http_build_query($params);
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

        $response = Http::accept('application/json')
            ->timeout($this->httpTimeout())
            ->post($config['token_url'], [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'code' => $code,
                'redirect_uri' => $config['redirect_uri'],
                'grant_type' => 'authorization_code',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Token exchange failed: '.$response->body());
        }

        return $response->json();
    }

    public function fetchGitHubUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->accept('application/json')
            ->timeout($this->httpTimeout())
            ->get('https://api.github.com/user');

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch GitHub user: '.$response->body());
        }

        return $response->json();
    }

    public function fetchJiraAccessibleResources(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->accept('application/json')
            ->timeout($this->httpTimeout())
            ->get('https://api.atlassian.com/oauth/token/accessible-resources');

        if ($response->failed()) {
            throw new \RuntimeException('Failed to fetch Jira accessible resources: '.$response->body());
        }

        return $response->json();
    }

    public function revokeJiraToken(OauthToken $token): void
    {
        $config = $this->providerConfig('jira');
        $tokenToRevoke = $token->refresh_token ?: $token->access_token;
        $hint = $token->refresh_token ? 'refresh_token' : 'access_token';

        $response = Http::acceptJson()
            ->asJson()
            ->timeout($this->httpTimeout())
            ->post('https://auth.atlassian.com/oauth/revoke', [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'token' => $tokenToRevoke,
                'token_type_hint' => $hint,
            ]);

        if ($response->failed() && $response->status() !== 400) {
            throw new \RuntimeException('Jira token revocation failed: '.$response->body());
        }
    }

    public function revokeGitHubToken(string $accessToken): void
    {
        $config = $this->providerConfig('github');

        $response = Http::withBasicAuth($config['client_id'], $config['client_secret'])
            ->accept('application/json')
            ->timeout($this->httpTimeout())
            ->delete('https://api.github.com/applications/'.$config['client_id'].'/grant', [
                'access_token' => $accessToken,
            ]);

        if ($response->failed() && $response->status() !== 404) {
            throw new \RuntimeException('GitHub token revocation failed: '.$response->body());
        }
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

        $response = Http::timeout($this->httpTimeout())
            ->post($config['token_url'], [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'refresh_token' => $token->refresh_token,
                'grant_type' => 'refresh_token',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Token refresh failed: '.$response->body());
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

        $response = Http::withToken($token->access_token)
            ->timeout($this->httpTimeout())
            ->$method($url, $options);

        if ($response->status() === 401 && $token->refresh_token) {
            $token = $this->refreshToken($token);
            $response = Http::withToken($token->access_token)
                ->timeout($this->httpTimeout())
                ->$method($url, $options);
        }

        return $response;
    }
}
