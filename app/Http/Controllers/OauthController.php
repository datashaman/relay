<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Services\OauthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OauthController extends Controller
{
    public function __construct(
        private OauthService $oauth,
    ) {}

    public function redirect(string $provider): RedirectResponse
    {
        return redirect()->away($this->oauth->generateAuthUrl($provider));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect('/sources')->with('error', 'OAuth authorization was denied.');
        }

        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        try {
            $validatedProvider = $this->oauth->validateState($request->input('state'));

            if ($validatedProvider !== $provider) {
                return redirect('/sources')->with('error', 'OAuth state mismatch.');
            }

            $tokenData = $this->oauth->exchangeCode($provider, $request->input('code'));

            $accountName = $this->resolveAccountName($provider, $tokenData);

            $source = Source::firstOrCreate(
                ['type' => $provider, 'external_account' => $accountName],
                ['name' => ucfirst($provider) . ' Connection', 'is_active' => true],
            );

            $this->oauth->storeToken($source, $provider, $tokenData);

            return redirect('/sources')->with('success', ucfirst($provider) . ' connected successfully.');
        } catch (\RuntimeException $e) {
            return redirect('/sources')->with('error', $e->getMessage());
        }
    }

    public function disconnect(string $provider): RedirectResponse
    {
        $source = Source::where('type', $provider)->first();

        if (! $source) {
            return redirect('/sources')->with('error', 'No ' . ucfirst($provider) . ' connection found.');
        }

        $token = $source->oauthTokens()->where('provider', $provider)->first();

        $revocationError = null;

        if ($token && $provider === 'github') {
            try {
                $this->oauth->revokeGitHubToken($token->access_token);
            } catch (\RuntimeException $e) {
                $revocationError = $e->getMessage();
            }
        }

        $source->oauthTokens()->delete();
        $source->delete();

        if ($revocationError) {
            return redirect('/sources')->with('warning', ucfirst($provider) . ' disconnected locally, but remote revocation failed: ' . $revocationError);
        }

        return redirect('/sources')->with('success', ucfirst($provider) . ' disconnected successfully.');
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
}
