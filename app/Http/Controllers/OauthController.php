<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Services\MobileOauthService;
use App\Services\OauthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class OauthController extends Controller
{
    public function __construct(
        private OauthService $oauth,
        private MobileOauthService $mobileOauth,
    ) {}

    public function redirect(string $provider): RedirectResponse
    {
        $authUrl = $this->oauth->generateAuthUrl($provider);

        if ($this->mobileOauth->isMobileOauth()) {
            $this->mobileOauth->openAuthUrl($authUrl);

            return redirect()->route('intake.index')->with('success', 'Opening browser for authentication...');
        }

        return redirect()->away($authUrl);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('intake.index')->with('error', 'OAuth authorization was denied.');
        }

        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        try {
            $validatedProvider = $this->oauth->validateState($request->input('state'));

            if ($validatedProvider !== $provider) {
                return redirect()->route('intake.index')->with('error', 'OAuth state mismatch.');
            }

            $tokenData = $this->oauth->exchangeCode($provider, $request->input('code'));

            if ($provider === 'jira') {
                return $this->handleJiraCallback($tokenData);
            }

            $accountName = $this->resolveAccountName($provider, $tokenData);

            $source = Source::firstOrCreate(
                ['type' => $provider, 'external_account' => $accountName],
                ['name' => ucfirst($provider).' Connection', 'is_active' => true],
            );

            $this->oauth->storeToken($source, $provider, $tokenData);

            if ($provider === 'github' && empty($source->config['repositories'] ?? [])) {
                return redirect()->route('github.select-repos', $source)
                    ->with('success', 'GitHub connected. Pick the repositories Relay should sync.');
            }

            return redirect()->route('intake.index')->with('success', ucfirst($provider).' connected successfully.');
        } catch (\RuntimeException $e) {
            return redirect()->route('intake.index')->with('error', $e->getMessage());
        }
    }

    public function disconnect(string $provider): RedirectResponse
    {
        $source = Source::where('type', $provider)->first();

        if (! $source) {
            return redirect()->route('intake.index')->with('error', 'No '.ucfirst($provider).' connection found.');
        }

        $token = $source->oauthTokens()->where('provider', $provider)->first();

        $revocationError = null;

        if ($token) {
            try {
                if ($provider === 'github') {
                    $this->oauth->revokeGitHubToken($token->access_token);
                } elseif ($provider === 'jira') {
                    $this->oauth->revokeJiraToken($token);
                }
            } catch (\RuntimeException $e) {
                $revocationError = $e->getMessage();
            }
        }

        $source->oauthTokens()->delete();
        $source->delete();

        if ($revocationError) {
            return redirect()->route('intake.index')->with('warning', ucfirst($provider).' disconnected locally, but remote revocation failed: '.$revocationError);
        }

        return redirect()->route('intake.index')->with('success', ucfirst($provider).' disconnected successfully.');
    }

    private function handleJiraCallback(array $tokenData): RedirectResponse
    {
        try {
            $sites = $this->oauth->fetchJiraAccessibleResources($tokenData['access_token']);
        } catch (\RuntimeException $e) {
            return redirect()->route('intake.index')->with('error', 'Failed to fetch Jira sites: '.$e->getMessage());
        }

        if (empty($sites)) {
            return redirect()->route('intake.index')->with('error', 'No accessible Jira sites found for this account.');
        }

        if (count($sites) === 1) {
            $site = $sites[0];

            $source = Source::firstOrCreate(
                ['type' => 'jira', 'external_account' => $site['name']],
                [
                    'name' => 'Jira: '.$site['name'],
                    'is_active' => true,
                    'config' => ['cloud_id' => $site['id'], 'site_url' => $site['url'] ?? null],
                ],
            );

            if ($source->wasRecentlyCreated === false) {
                $source->update(['config' => ['cloud_id' => $site['id'], 'site_url' => $site['url'] ?? null]]);
            }

            $this->oauth->storeToken($source, 'jira', $tokenData);

            if (empty($source->config['projects'] ?? [])) {
                return redirect()->route('jira.select-projects', $source)
                    ->with('success', 'Jira connected. Pick the projects Relay should sync.');
            }

            return redirect()->route('intake.index')->with('success', 'Jira connected successfully ('.$site['name'].').');
        }

        $pendingKey = $this->getJiraPendingCacheKey();
        Cache::put($pendingKey, [
            'token_data' => $tokenData,
            'sites' => $sites,
        ], now()->addMinutes(10));

        return redirect()->route('jira.select-site.form')->with('sites', $sites);
    }

    private function resolveAccountName(string $provider, array $tokenData): string
    {
        if ($provider === 'github') {
            try {
                $user = $this->oauth->fetchGitHubUser($tokenData['access_token']);

                return $user['login'];
            } catch (\RuntimeException) {
                return 'GitHub (unknown)';
            }
        }

        return $tokenData['account_name'] ?? $provider;
    }

    private function getJiraPendingCacheKey(): string
    {
        return 'jira_pending_site_selection';
    }
}
