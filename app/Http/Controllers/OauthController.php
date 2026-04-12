<?php

namespace App\Http\Controllers;

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

            $source = \App\Models\Source::firstOrCreate(
                ['type' => $provider, 'external_account' => $tokenData['account_name'] ?? $provider],
                ['name' => ucfirst($provider) . ' Connection', 'is_active' => true],
            );

            $this->oauth->storeToken($source, $provider, $tokenData);

            return redirect('/sources')->with('success', ucfirst($provider) . ' connected successfully.');
        } catch (\RuntimeException $e) {
            return redirect('/sources')->with('error', $e->getMessage());
        }
    }
}
